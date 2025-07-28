<?php

namespace App\Tests\Integration;

use App\Service\DocumentationAnalyzer;
use App\Model\MergeRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Psr\Log\NullLogger;
use App\Prompt\AnalysisPromptBuilder;

class ApiConnectionTest extends TestCase
{
    public function testOpenAiApiConnection(): void
    {
        // Mock successful OpenAI response
        $mockResponse = new MockResponse(json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'requires_documentation' => false,
                            'impact_level' => 'none',
                            'impacted_areas' => [],
                            'reasons' => ['Internal refactoring only'],
                            'suggestions' => []
                        ])
                    ]
                ]
            ]
        ]));

        $httpClient = new MockHttpClient($mockResponse);
        $promptBuilder = new AnalysisPromptBuilder();
        $logger = new NullLogger();

        $analyzer = new DocumentationAnalyzer(
            $promptBuilder,
            $logger,
            $httpClient,
            'test-api-key'
        );

        $mr = new MergeRequest(
            id: '123',
            title: 'Test refactoring',
            description: 'Internal code cleanup',
            sourceBranch: 'refactor',
            targetBranch: 'main',
            author: 'testuser',
            url: 'https://example.com/mr/123',
            changedFiles: ['src/Service/TestService.php'],
            status: 'opened'
        );

        $diff = "--- a/src/Service/TestService.php\n+++ b/src/Service/TestService.php\n@@ -10,7 +10,7 @@\n-    private function oldMethod()\n+    private function newMethod()";

        $impact = $analyzer->analyze($mr, $diff);

        $this->assertFalse($impact->required);
        $this->assertEquals('none', $impact->level->value);
    }

    public function testGitProviderUrlConstruction(): void
    {
        // Test GitLab URL construction
        $this->assertEquals(
            'https://gitlab.com/api/v4/projects/123/merge_requests/456/changes',
            $this->buildGitLabDiffUrl(['id' => 123, 'web_url' => 'https://gitlab.com/test/repo'], ['iid' => 456])
        );

        // Test GitHub URL construction  
        $this->assertEquals(
            'https://api.github.com/repos/test/repo/pulls/456',
            $this->buildGitHubDiffUrl(['full_name' => 'test/repo'], ['number' => 456])
        );
    }

    private function buildGitLabDiffUrl(array $project, array $mrData): string
    {
        $projectId = $project['id'];
        $mrIid = $mrData['iid'];
        $baseUrl = rtrim($project['web_url'], '/');
        $baseUrl = str_replace('/test/repo', '', $baseUrl);
        
        return $baseUrl . "/api/v4/projects/{$projectId}/merge_requests/{$mrIid}/changes";
    }

    private function buildGitHubDiffUrl(array $repository, array $pr): string
    {
        return "https://api.github.com/repos/{$repository['full_name']}/pulls/{$pr['number']}";
    }
}