# CLAUDE.md - Symfony AI Documentation Impact Analyzer

## Project Overview

You are developing a Documentation Impact Analyzer that uses Symfony AI to analyze merge requests and determine if code changes require user documentation updates. This tool helps maintain documentation quality by automatically flagging changes that impact user-facing features, APIs, or configurations.

## How to start the Symfony server

```
symfony local:server:start --allow-all-ip
```

## Symfony Development Rules and Best Practices

### 1. Controller Best Practices

#### Always extend AbstractController
```php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends AbstractController
{
    // Provides helper methods like render(), json(), redirectToRoute()
}
```

#### Use PHP attributes for routes
```php
#[Route('/webhook/{provider}', name: 'webhook_handle', methods: ['POST'])]
public function handleWebhook(string $provider, Request $request): Response
{
    // Always specify name and methods for clarity
}
```

#### Type-hint all method parameters and return types
```php
public function handleWebhook(
    string $provider, 
    Request $request,
    DocumentationAnalyzer $analyzer // Services are automatically injected
): Response {
    // Return type is mandatory
}
```

### 2. Service Architecture

#### Services should be immutable with constructor injection
```php
namespace App\Service;

final readonly class DocumentationAnalyzer
{
    public function __construct(
        private ChainInterface $chain,
        private AnalysisPromptBuilder $promptBuilder,
        private LoggerInterface $logger,
    ) {}
    
    // No setters, all dependencies injected via constructor
}
```

#### Use interfaces for flexibility
```php
namespace App\Service;

interface GitProviderInterface
{
    public function validateWebhook(Request $request): bool;
    public function parseMergeRequest(Request $request): MergeRequest;
    public function fetchMergeRequestDiff(MergeRequest $mr): string;
    public function postComment(MergeRequest $mr, DocumentationImpact $impact): void;
}
```

#### Implement specific providers
```php
final class GitLabService implements GitProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiToken,
        private readonly string $webhookSecret,
    ) {}
}
```

### 3. Dependency Injection

#### Configure services in services.yaml
```yaml
services:
    _defaults:
        autowire: true      # Automatically inject services
        autoconfigure: true # Automatically configure tags
        public: false       # Services are private by default

    App\:
        resource: '../src/'
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Entity/'
            - '../src/Kernel.php'

    # Explicit service configuration when needed
    App\Service\GitLabService:
        arguments:
            $apiToken: '%env(GITLAB_TOKEN)%'
            $webhookSecret: '%env(GITLAB_WEBHOOK_SECRET)%'
```

#### Use parameters for configuration values
```yaml
parameters:
    app.max_diff_size: 50000
    app.ai_temperature: 0.3
    
services:
    App\Service\DocumentationAnalyzer:
        arguments:
            $maxDiffSize: '%app.max_diff_size%'
```

### 4. Symfony AI Integration

#### Configure AI in config/packages/ai.yaml
```yaml
ai:
    platform:
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
    
    chains:
        documentation_analyzer:
            model:
                provider: 'anthropic'
                name: 'claude-3-5-sonnet-20241022'
                temperature: 0.3
                response_format:
                    type: 'json_object'
```

#### Use ChainInterface for AI calls
```php
use PhpLlm\LlmChain\ChainInterface;
use PhpLlm\LlmChain\Model\Message\Message;
use PhpLlm\LlmChain\Model\Message\MessageBag;

public function __construct(
    private ChainInterface $chain,
) {}

public function analyze(MergeRequest $mr, string $diff): DocumentationImpact
{
    $messages = new MessageBag(
        Message::forSystem($this->buildSystemPrompt()),
        Message::ofUser($this->buildAnalysisPrompt($mr, $diff)),
    );
    
    $response = $this->chain->call($messages, [
        'response_format' => ['type' => 'json_object'],
    ]);
    
    return $this->parseResponse($response);
}
```

### 5. Error Handling

#### Always handle exceptions appropriately
```php
public function handleWebhook(string $provider, Request $request): Response
{
    try {
        if (!$this->gitProvider->validateWebhook($request)) {
            throw new UnauthorizedHttpException('Invalid webhook signature');
        }
        
        $mergeRequest = $this->gitProvider->parseMergeRequest($request);
        // Process...
        
    } catch (GitProviderException $e) {
        $this->logger->error('Git provider error', [
            'provider' => $provider,
            'error' => $e->getMessage(),
        ]);
        
        return new Response('Processing error', Response::HTTP_SERVICE_UNAVAILABLE);
    }
    
    return new Response('Processed', Response::HTTP_OK);
}
```

### 6. Value Objects and Models

#### Create immutable value objects
```php
namespace App\Model;

final readonly class DocumentationImpact
{
    public function __construct(
        public ImpactLevel $level,
        public bool $required,
        public array $impactedAreas,
        public array $reasons,
        public array $suggestions,
    ) {}
    
    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'required' => $this->required,
            'impacted_areas' => $this->impactedAreas,
            'reasons' => $this->reasons,
            'suggestions' => $this->suggestions,
        ];
    }
}
```

#### Use enums for fixed values
```php
namespace App\Model;

enum ImpactLevel: string
{
    case NONE = 'none';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';
    
    public function getEmoji(): string
    {
        return match($this) {
            self::NONE => '✅',
            self::LOW => '🟡',
            self::MEDIUM => '🟠',
            self::HIGH => '🔴',
            self::CRITICAL => '🚨',
        };
    }
}
```

### 7. Testing Patterns

#### Unit test services
```php
namespace App\Tests\Service;

use App\Service\DocumentationAnalyzer;
use PHPUnit\Framework\TestCase;

class DocumentationAnalyzerTest extends TestCase
{
    private DocumentationAnalyzer $analyzer;
    
    protected function setUp(): void
    {
        $chain = $this->createMock(ChainInterface::class);
        $promptBuilder = $this->createMock(AnalysisPromptBuilder::class);
        
        $this->analyzer = new DocumentationAnalyzer($chain, $promptBuilder);
    }
    
    public function testAnalyzeNewApiEndpoint(): void
    {
        // Test implementation
    }
}
```

#### Integration test controllers
```php
namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WebhookControllerTest extends WebTestCase
{
    public function testGitLabWebhook(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/webhook/gitlab', [], [], [
            'HTTP_X-Gitlab-Token' => 'test-secret',
        ], json_encode($this->getMergeRequestPayload()));
        
        $this->assertResponseIsSuccessful();
    }
}
```

### 8. Environment Variables

#### Always use environment variables for sensitive data
```bash
# .env.local (never commit this)
ANTHROPIC_API_KEY=sk-ant-...
GITLAB_TOKEN=glpat-...
GITLAB_WEBHOOK_SECRET=...
```

#### Reference them in configuration
```yaml
# config/packages/ai.yaml
ai:
    platform:
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
```

### 9. Logging Best Practices

```php
use Psr\Log\LoggerInterface;

public function __construct(
    private LoggerInterface $logger,
) {}

public function analyze(MergeRequest $mr, string $diff): DocumentationImpact
{
    $this->logger->info('Analyzing merge request', [
        'mr_id' => $mr->getId(),
        'diff_size' => strlen($diff),
    ]);
    
    try {
        $result = $this->performAnalysis($mr, $diff);
        
        $this->logger->info('Analysis completed', [
            'mr_id' => $mr->getId(),
            'impact_level' => $result->level->value,
            'required' => $result->required,
        ]);
        
        return $result;
    } catch (\Exception $e) {
        $this->logger->error('Analysis failed', [
            'mr_id' => $mr->getId(),
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

### 10. Symfony AI Specific Rules

#### Always handle AI responses safely
```php
private function parseResponse(ResponseInterface $response): DocumentationImpact
{
    try {
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        
        // Validate required fields exist
        if (!isset($data['requires_documentation'], $data['impact_level'])) {
            throw new \RuntimeException('Invalid AI response structure');
        }
        
        return new DocumentationImpact(
            level: ImpactLevel::from($data['impact_level']),
            required: $data['requires_documentation'],
            impactedAreas: $data['impacted_areas'] ?? [],
            reasons: $data['reasons'] ?? [],
            suggestions: $data['suggestions'] ?? [],
        );
    } catch (\JsonException $e) {
        $this->logger->error('Failed to parse AI response', [
            'response' => $response->getContent(),
            'error' => $e->getMessage(),
        ]);
        
        // Return safe default
        return new DocumentationImpact(
            level: ImpactLevel::MEDIUM,
            required: true,
            impactedAreas: ['unknown'],
            reasons: ['AI analysis failed - manual review recommended'],
            suggestions: [],
        );
    }
}
```

#### Use prompt templates with Twig
```php
// src/Service/AnalysisPromptBuilder.php
namespace App\Service;

use Twig\Environment;

final readonly class AnalysisPromptBuilder
{
    public function __construct(
        private Environment $twig,
    ) {}
    
    public function build(MergeRequest $mr, string $diff): string
    {
        return $this->twig->render('prompts/documentation_analysis.txt.twig', [
            'mr' => $mr,
            'diff' => $diff,
            'max_diff_length' => 10000,
        ]);
    }
}
```

### 11. Security Considerations

#### Always validate webhook signatures
```php
public function validateWebhook(Request $request): bool
{
    $signature = $request->headers->get('X-Gitlab-Token');
    
    if (!$signature || !hash_equals($this->webhookSecret, $signature)) {
        $this->logger->warning('Invalid webhook signature', [
            'ip' => $request->getClientIp(),
        ]);
        return false;
    }
    
    return true;
}
```

#### Sanitize user input before AI processing
```php
private function sanitizeDiff(string $diff): string
{
    // Remove any potential injection attempts
    $diff = strip_tags($diff);
    
    // Limit diff size to prevent token overflow
    if (strlen($diff) > $this->maxDiffSize) {
        $diff = substr($diff, 0, $this->maxDiffSize) . "\n... [truncated]";
    }
    
    return $diff;
}
```

### 12. Performance Optimization

#### Cache AI responses when appropriate
```php
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

public function __construct(
    private CacheInterface $cache,
) {}

public function analyze(MergeRequest $mr, string $diff): DocumentationImpact
{
    $cacheKey = 'doc_impact_' . md5($mr->getId() . $diff);
    
    return $this->cache->get($cacheKey, function (ItemInterface $item) use ($mr, $diff) {
        $item->expiresAfter(3600); // Cache for 1 hour
        
        return $this->performAnalysis($mr, $diff);
    });
}
```

## Project-Specific Guidelines

### API Response Structure
All AI responses should follow this JSON structure:
```json
{
    "requires_documentation": true,
    "impact_level": "high",
    "impacted_areas": ["user_guide", "api_docs"],
    "reasons": [
        "New API endpoint /api/v2/users added",
        "Breaking change in authentication flow"
    ],
    "suggestions": [
        "Document the new endpoint parameters and response format",
        "Update authentication guide with new flow"
    ]
}
```

### Comment Formatting
Use the Twig template to format MR comments with:
- Clear impact level with emoji
- Bulleted list of reasons
- Actionable suggestions
- Links to documentation guidelines

### Error Recovery
- Always provide meaningful fallback responses
- Log all AI failures for monitoring
- Never expose AI errors to webhook responses
- Use circuit breaker pattern for AI calls

### Testing Strategy
1. Mock AI responses in unit tests
2. Use fixtures for different MR scenarios
3. Test webhook signature validation thoroughly
4. Verify comment formatting with snapshots

Remember: This tool aims to improve documentation quality without being overly aggressive. When in doubt, suggest documentation review rather than blocking the MR.
