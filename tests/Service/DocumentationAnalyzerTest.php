<?php

namespace App\Tests\Service;

use App\Model\MergeRequest;
use App\Prompt\AnalysisPromptBuilder;
use App\Service\DocumentationAnalyzer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DocumentationAnalyzerTest extends TestCase
{
    private DocumentationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $promptBuilder = new AnalysisPromptBuilder();
        $logger = new NullLogger();
        $httpClient = new MockHttpClient();
        
        $this->analyzer = new DocumentationAnalyzer(
            $promptBuilder,
            $logger,
            $httpClient,
            'test-api-key'
        );
    }

    public function testAnalyzeApiEndpointAddition(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'content' => [
                [
                    'text' => json_encode([
                        'requires_documentation' => true,
                        'impact_level' => 'high',
                        'impacted_areas' => ['api_docs'],
                        'reasons' => ['New API endpoint added'],
                        'suggestions' => [
                            'Document the new POST /api/users endpoint',
                            'Add authentication examples with TOKEN=abc123',
                            'Include response format in API reference'
                        ]
                    ])
                ]
            ]
        ]));

        $httpClient = new MockHttpClient($mockResponse);
        $analyzer = new DocumentationAnalyzer(
            new AnalysisPromptBuilder(),
            new NullLogger(),
            $httpClient,
            'test-api-key'
        );

        $mr = new MergeRequest(
            id: '123',
            title: 'Add user creation endpoint',
            description: 'New API for user management',
            sourceBranch: 'feature/users',
            targetBranch: 'main',
            author: 'dev',
            url: 'https://example.com/mr/123',
            changedFiles: ['src/Controller/UserController.php'],
            status: 'opened'
        );

        $diff = '+++ b/src/Controller/UserController.php
@@ -0,0 +1,10 @@
+    #[Route(\'/api/users\', methods: [\'POST\'])]
+    public function createUser(Request $request): Response
+    {
+        // Create user logic
+    }';

        $impact = $analyzer->analyze($mr, $diff);

        $this->assertTrue($impact->required);
        $this->assertEquals('high', $impact->level->value);
        $this->assertContains('api_docs', $impact->impactedAreas);
        $this->assertContains('New API endpoint added', $impact->reasons);
        
        // Test suggestion formatting
        $suggestions = $impact->suggestions;
        $this->assertStringContainsString('**POST** `/api/users`', $suggestions[0]);
        $this->assertStringContainsString('`TOKEN=abc123`', $suggestions[1]);
    }

    public function testAnalyzeInternalRefactoring(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'content' => [
                [
                    'text' => json_encode([
                        'requires_documentation' => false,
                        'impact_level' => 'none',
                        'impacted_areas' => [],
                        'reasons' => ['Internal refactoring with no user impact'],
                        'suggestions' => []
                    ])
                ]
            ]
        ]));

        $httpClient = new MockHttpClient($mockResponse);
        $analyzer = new DocumentationAnalyzer(
            new AnalysisPromptBuilder(),
            new NullLogger(),
            $httpClient,
            'test-api-key'
        );

        $mr = new MergeRequest(
            id: '456',
            title: 'Refactor service layer',
            description: 'Internal code cleanup',
            sourceBranch: 'refactor/services',
            targetBranch: 'main',
            author: 'dev',
            url: 'https://example.com/mr/456',
            changedFiles: ['src/Service/UserService.php'],
            status: 'opened'
        );

        $diff = '+++ b/src/Service/UserService.php
@@ -10,8 +10,8 @@
-    private function oldMethod()
+    private function newMethod()';

        $impact = $analyzer->analyze($mr, $diff);

        $this->assertFalse($impact->required);
        $this->assertEquals('none', $impact->level->value);
        $this->assertEmpty($impact->impactedAreas);
        $this->assertEmpty($impact->suggestions);
    }

    public function testAnalyzeConfigurationChange(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'content' => [
                [
                    'text' => json_encode([
                        'requires_documentation' => true,
                        'impact_level' => 'medium',
                        'impacted_areas' => ['configuration'],
                        'reasons' => ['New environment variable added'],
                        'suggestions' => [
                            'Document FEATURE_FLAG=true environment variable',
                            'Update deployment configuration guide'
                        ]
                    ])
                ]
            ]
        ]));

        $httpClient = new MockHttpClient($mockResponse);
        $analyzer = new DocumentationAnalyzer(
            new AnalysisPromptBuilder(),
            new NullLogger(),
            $httpClient,
            'test-api-key'
        );

        $mr = new MergeRequest(
            id: '789',
            title: 'Add feature flag configuration',
            description: 'New config option',
            sourceBranch: 'feature/config',
            targetBranch: 'main',
            author: 'dev',
            url: 'https://example.com/mr/789',
            changedFiles: ['.env', 'config/services.yaml'],
            status: 'opened'
        );

        $diff = '+++ b/.env
@@ -5,0 +5,1 @@
+FEATURE_FLAG=true';

        $impact = $analyzer->analyze($mr, $diff);

        $this->assertTrue($impact->required);
        $this->assertEquals('medium', $impact->level->value);
        $this->assertContains('configuration', $impact->impactedAreas);
        
        // Test environment variable formatting
        $suggestions = $impact->suggestions;
        $this->assertStringContainsString('`FEATURE_FLAG=true`', $suggestions[0]);
    }

    public function testHandleInvalidApiResponse(): void
    {
        $mockResponse = new MockResponse('invalid json');
        
        $httpClient = new MockHttpClient($mockResponse);
        $analyzer = new DocumentationAnalyzer(
            new AnalysisPromptBuilder(),
            new NullLogger(),
            $httpClient,
            'test-api-key'
        );

        $mr = new MergeRequest(
            id: '999',
            title: 'Test change',
            description: 'Test',
            sourceBranch: 'test',
            targetBranch: 'main',
            author: 'dev',
            url: 'https://example.com/mr/999',
            changedFiles: ['test.php'],
            status: 'opened'
        );

        $impact = $analyzer->analyze($mr, 'test diff');

        // Should return safe default
        $this->assertTrue($impact->required);
        $this->assertEquals('medium', $impact->level->value);
        $this->assertContains('unknown', $impact->impactedAreas);
        $this->assertStringContainsString('AI analysis failed', $impact->reasons[0]);
    }
}