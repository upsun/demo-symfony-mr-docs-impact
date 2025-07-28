# Documentation Impact Analyzer - Technical Specifications

## Project Overview

Build a Symfony AI-powered tool that analyzes merge requests to determine if code changes require user documentation updates. The tool will help maintain documentation quality by flagging changes that impact user-facing features, APIs, or configurations.

## Core Functionality

### What It Does
1. Receives merge request webhooks from GitLab/GitHub
2. Analyzes code changes using AI to identify documentation impacts
3. Comments on the MR with:
   - Whether documentation is needed (Yes/No/Maybe)
   - What type of documentation (User guide, API docs, Configuration)
   - Specific areas that need documenting
   - Suggested documentation snippets

### Documentation Impact Categories
- **User-Facing Changes**: New features, changed behaviors, UI modifications
- **API Changes**: New endpoints, parameter changes, response format updates
- **Configuration Changes**: New env variables, config files, settings
- **Breaking Changes**: Backwards incompatible modifications
- **Performance Changes**: That users should be aware of

## Simplified Architecture

### Directory Structure
```
documentation-impact-analyzer/
├── src/
│   ├── Controller/
│   │   └── WebhookController.php
│   ├── Service/
│   │   ├── GitProviderInterface.php
│   │   ├── GitHubService.php
│   │   ├── GitLabService.php
│   │   └── DocumentationAnalyzer.php
│   ├── Model/
│   │   ├── MergeRequest.php
│   │   ├── DocumentationImpact.php
│   │   └── ImpactLevel.php
│   └── Prompt/
│       └── AnalysisPromptBuilder.php
├── config/
│   ├── packages/
│   │   └── ai.yaml
│   └── services.yaml
├── templates/
│   └── comment/
│       └── documentation_impact.md.twig
├── tests/
└── .upsun/
    └── config.yaml
```

## Implementation TODO List

### Week 1: Basic Setup and AI Integration

#### Day 1-2: Project Setup
- [ ] Create new Symfony 7.1 project
- [ ] Install Symfony AI package
- [ ] Set up basic folder structure
- [ ] Configure environment variables
- [ ] Create `.upsun/config.yaml`
- [ ] Initialize Git repository

#### Day 3-4: Webhook Handler
- [ ] Create `WebhookController` with routes:
  - [ ] POST `/webhook/github`
  - [ ] POST `/webhook/gitlab`
- [ ] Implement webhook signature validation
- [ ] Create `MergeRequest` model to hold MR data
- [ ] Add basic logging for incoming webhooks
- [ ] Write tests for webhook validation

#### Day 5: Git Provider Integration
- [ ] Create `GitProviderInterface` with methods:
  - [ ] `fetchMergeRequestDiff()`
  - [ ] `postComment()`
  - [ ] `getMergeRequestDetails()`
- [ ] Implement `GitLabService`
- [ ] Implement `GitHubService`
- [ ] Add configuration for API tokens
- [ ] Test API connections

### Week 2: AI Analysis Implementation

#### Day 1-2: Documentation Analyzer
- [ ] Create `DocumentationAnalyzer` service
- [ ] Implement `ImpactLevel` enum (NONE, LOW, MEDIUM, HIGH, CRITICAL)
- [ ] Create `DocumentationImpact` model:
  ```php
  class DocumentationImpact {
      public ImpactLevel $level;
      public bool $required;
      public array $impactedAreas; // ['user_guide', 'api_docs', 'config']
      public array $reasons;
      public array $suggestions;
  }
  ```
- [ ] Write initial analysis method

#### Day 3-4: Prompt Engineering
- [ ] Create `AnalysisPromptBuilder` class
- [ ] Design main analysis prompt template
- [ ] Add file type specific prompts:
  - [ ] Controller changes
  - [ ] Entity/Model changes
  - [ ] Configuration changes
  - [ ] Template changes
- [ ] Include examples in prompts for better results

#### Day 5: Response Formatting
- [ ] Create Twig template for MR comments
- [ ] Implement markdown formatting for suggestions
- [ ] Add emoji indicators for impact levels
- [ ] Format code examples in suggestions
- [ ] Test comment rendering

### Week 3: Testing and Deployment

#### Day 1-2: Comprehensive Testing
- [ ] Create test fixtures with sample MRs
- [ ] Test different types of changes:
  - [ ] New API endpoint
  - [ ] Config parameter addition
  - [ ] Breaking change
  - [ ] UI text change
  - [ ] Internal refactoring (no doc needed)
- [ ] Mock AI responses for consistent testing
- [ ] Test webhook integration end-to-end

#### Day 3-4: Deployment to Upsun
- [ ] Configure Upsun environment variables
- [ ] Deploy to development environment
- [ ] Test webhook connectivity
- [ ] Monitor AI API usage
- [ ] Set up basic monitoring/logging

#### Day 5: Documentation & Demo
- [ ] Write README with setup instructions
- [ ] Create demo video
- [ ] Document configuration options
- [ ] Prepare example MRs for article
- [ ] Calculate cost metrics

## Code Structure

### WebhookController.php
```php
<?php

namespace App\Controller;

use App\Service\GitProviderInterface;
use App\Service\DocumentationAnalyzer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController
{
    public function __construct(
        private DocumentationAnalyzer $analyzer,
        private GitProviderInterface $gitProvider,
    ) {}

    #[Route('/webhook/{provider}', methods: ['POST'])]
    public function handleWebhook(string $provider, Request $request): Response
    {
        // 1. Validate webhook signature
        if (!$this->gitProvider->validateWebhook($request)) {
            return new Response('Invalid signature', 401);
        }

        // 2. Extract MR data
        $mergeRequest = $this->gitProvider->parseMergeRequest($request);
        
        // 3. Skip if not relevant (draft, WIP, closed)
        if (!$this->shouldAnalyze($mergeRequest)) {
            return new Response('Skipped', 200);
        }

        // 4. Fetch diff
        $diff = $this->gitProvider->fetchMergeRequestDiff($mergeRequest);
        
        // 5. Analyze with AI
        $impact = $this->analyzer->analyze($mergeRequest, $diff);
        
        // 6. Post comment if needed
        if ($impact->required || $impact->level->value >= ImpactLevel::MEDIUM->value) {
            $this->gitProvider->postComment($mergeRequest, $impact);
        }

        return new Response('Processed', 200);
    }
}
```

### DocumentationAnalyzer.php
```php
<?php

namespace App\Service;

use App\Model\DocumentationImpact;
use App\Model\MergeRequest;
use App\Prompt\AnalysisPromptBuilder;
use PhpLlm\LlmChain\ChainInterface;

class DocumentationAnalyzer
{
    public function __construct(
        private ChainInterface $chain,
        private AnalysisPromptBuilder $promptBuilder,
    ) {}

    public function analyze(MergeRequest $mr, string $diff): DocumentationImpact
    {
        $prompt = $this->promptBuilder->build($mr, $diff);
        
        $response = $this->chain->call($prompt, [
            'response_format' => ['type' => 'json_object'],
        ]);

        return $this->parseResponse($response);
    }
}
```

### AI Configuration (config/packages/ai.yaml)
```yaml
ai:
    platform:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
    
    chains:
        default:
            model:
                provider: 'openai'
                name: 'gpt-4o-mini'  # Cost-effective for this use case
                temperature: 0.3    # Lower for more consistent analysis
                response_format:
                    type: 'json_object'
```

### Upsun Configuration (.upsun/config.yaml)
```yaml
applications:
    app:
        type: 'php:8.3'
        
        dependencies:
            php:
                composer/composer: '^2'
        
        build:
            flavor: none
            
        hooks:
            build: |
                set -e
                composer install --no-dev --optimize-autoloader
                
        web:
            locations:
                "/":
                    root: "public"
                    expires: 1h
                    passthru: "/index.php"
                    
        variables:
            php:
                opcache.preload: config/preload.php
                opcache.memory_consumption: 256
                opcache.max_accelerated_files: 20000

services:
    db:
        type: postgresql:15
        disk: 256  # Small disk, we're not storing much
```

## Prompt Template Example

```
You are a documentation expert analyzing code changes to determine if user documentation needs to be updated.

Analyze the following merge request:
- Title: {{ mr.title }}
- Description: {{ mr.description }}
- Changed files: {{ mr.changedFiles|join(', ') }}

Diff content:
```
{{ diff }}
```

Determine if this change requires documentation updates by analyzing:
1. Does it change user-facing functionality?
2. Does it add/modify/remove API endpoints?
3. Does it change configuration options?
4. Does it introduce breaking changes?
5. Does it affect performance in ways users should know?

Respond in JSON format:
{
    "requires_documentation": true/false,
    "impact_level": "none|low|medium|high|critical",
    "impacted_areas": ["user_guide", "api_docs", "configuration", "migration_guide"],
    "reasons": ["List of specific reasons why documentation is needed"],
    "suggestions": ["Specific documentation suggestions with examples"]
}
```

## Environment Variables
```bash
# Git Providers
GITHUB_TOKEN=
GITHUB_WEBHOOK_SECRET=
GITLAB_TOKEN=
GITLAB_WEBHOOK_SECRET=

# AI
OPENAI_API_KEY=

# Application
APP_ENV=prod
APP_SECRET=
```

## Example Scenarios

### Scenario 1: New API Endpoint
**Change**: Adding a new REST endpoint
```php
#[Route('/api/users/{id}/preferences', methods: ['GET'])]
public function getUserPreferences(int $id): JsonResponse
```

**Expected Output**:
- Requires documentation: **Yes**
- Impact level: **High**
- Areas: API Documentation
- Suggestion: "Document new endpoint GET /api/users/{id}/preferences including response format, authentication requirements, and example usage"

### Scenario 2: Internal Refactoring
**Change**: Extracting method to service class
```php
- $this->processData($data);
+ $this->dataProcessor->process($data);
```

**Expected Output**:
- Requires documentation: **No**
- Impact level: **None**
- Reason: "Internal refactoring with no user-facing changes"

### Scenario 3: Configuration Change
**Change**: Adding new environment variable
```php
parameters:
    app.feature.new_dashboard: '%env(bool:FEATURE_NEW_DASHBOARD)%'
```

**Expected Output**:
- Requires documentation: **Yes**
- Impact level: **Medium**
- Areas: Configuration Guide
- Suggestion: "Document new environment variable FEATURE_NEW_DASHBOARD: purpose, default value, and impact on application behavior"

## Success Metrics
- Analysis time per MR: < 30 seconds
- Cost per analysis: < $0.05
- Accuracy rate: > 90% (based on manual review)
- False positive rate: < 15%
- Developer adoption: > 80% find it helpful

## Article Demo Flow

1. **Setup**: Show simple configuration on Upsun
2. **Demo MR 1**: API endpoint addition → Clear documentation needed
3. **Demo MR 2**: Internal refactoring → No documentation needed
4. **Demo MR 3**: Config change → Specific documentation guidance
5. **Results**: Time saved, documentation completeness improved
6. **Cost Analysis**: Show actual API costs for typical usage

## Future Enhancements (Post-Article)
- Cache analysis for similar code patterns
- Learn from feedback (was documentation actually needed?)
- Generate draft documentation snippets
- Integration with documentation platforms
- Slack/Teams notifications for high-impact changes