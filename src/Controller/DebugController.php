<?php

namespace App\Controller;

use App\Model\DocumentationImpact;
use App\Model\ImpactLevel;
use App\Model\MergeRequest;
use App\Service\GitHubService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DebugController extends AbstractController
{
    #[Route('/debug/test-comment', name: 'debug_test_comment')]
    public function testComment(
        GitHubService $gitHubService,
        LoggerInterface $logger
    ): Response {
        // Debug logging for production
        $logger->info('Debug test comment endpoint accessed in environment: ' . $this->getParameter('kernel.environment'));

        $logger->info('Debug test comment endpoint called');

        // Create a test merge request
        $mr = new MergeRequest(
            id: '123',
            title: 'Test PR for debugging',
            description: 'This is a test PR to debug comment posting',
            sourceBranch: 'feature/test',
            targetBranch: 'main',
            author: 'testuser',
            url: 'https://github.com/test/repo/pull/123', // Change this to your actual repo
            changedFiles: ['src/Controller/TestController.php'],
            status: 'opened'
        );

        // Create a test documentation impact
        $impact = new DocumentationImpact(
            level: ImpactLevel::HIGH,
            required: true,
            impactedAreas: ['api_docs'],
            reasons: ['New API endpoint added for testing'],
            suggestions: [
                'Document the new endpoint in API reference',
                'Add authentication examples',
                'Update API changelog'
            ]
        );

        $logger->info('Test data created', [
            'mr_id' => $mr->id,
            'mr_url' => $mr->url,
            'impact_level' => $impact->level->value,
            'should_comment' => $impact->shouldComment(),
        ]);

        try {
            // Test the comment posting
            $gitHubService->postComment($mr, $impact);
            
            $logger->info('Test comment posting completed');
            
            return new Response('Test comment posting completed. Check logs for details.', 200);
            
        } catch (\Exception $e) {
            $logger->error('Test comment posting failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return new Response('Test failed: ' . $e->getMessage(), 500);
        }
    }

    #[Route('/debug/github-config', name: 'debug_github_config')]
    public function githubConfig(LoggerInterface $logger): Response
    {
        // Debug logging for production
        $logger->info('Debug github config endpoint accessed in environment: ' . $this->getParameter('kernel.environment'));

        $githubToken = $_ENV['GITHUB_TOKEN'] ?? 'NOT_SET';
        $githubSecret = $_ENV['GITHUB_WEBHOOK_SECRET'] ?? 'NOT_SET';
        
        $config = [
            'github_token_set' => $githubToken !== 'NOT_SET',
            'github_token_length' => $githubToken !== 'NOT_SET' ? strlen($githubToken) : 0,
            'github_secret_set' => $githubSecret !== 'NOT_SET',
            'environment' => $this->getParameter('kernel.environment'),
        ];
        
        $logger->info('GitHub configuration check', $config);
        
        return $this->json($config);
    }
}