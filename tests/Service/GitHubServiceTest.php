<?php

namespace App\Tests\Service;

use App\Service\GitHubService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitHubServiceTest extends TestCase
{
    private GitHubService $service;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->service = new GitHubService(
            $this->httpClient,
            'test-token',
            'test-secret',
            $this->logger
        );
    }

    public function testValidateWebhookSuccess(): void
    {
        $payload = 'test payload';
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, 'test-secret');
        
        $request = new Request([], [], [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $expectedSignature,
        ], $payload);

        $this->assertTrue($this->service->validateWebhook($request));
    }

    public function testValidateWebhookMissingSignature(): void
    {
        $request = new Request();

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Missing GitHub webhook signature');

        $this->assertFalse($this->service->validateWebhook($request));
    }

    public function testValidateWebhookInvalidSignature(): void
    {
        $request = new Request([], [], [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
        ], 'test payload');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Invalid GitHub webhook signature', $this->anything());

        $this->assertFalse($this->service->validateWebhook($request));
    }

    public function testParseMergeRequestSuccess(): void
    {
        $payload = [
            'pull_request' => [
                'number' => 123,
                'title' => 'Test PR',
                'body' => 'Test description',
                'state' => 'open',
                'draft' => false,
                'html_url' => 'https://github.com/test/repo/pull/123',
                'head' => ['ref' => 'feature'],
                'base' => ['ref' => 'main'],
                'user' => ['login' => 'testuser'],
            ],
            'repository' => [
                'full_name' => 'test/repo',
            ],
        ];

        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $mr = $this->service->parseMergeRequest($request);

        $this->assertEquals('123', $mr->id);
        $this->assertEquals('Test PR', $mr->title);
        $this->assertEquals('Test description', $mr->description);
        $this->assertEquals('feature', $mr->sourceBranch);
        $this->assertEquals('main', $mr->targetBranch);
        $this->assertEquals('testuser', $mr->author);
        $this->assertEquals('opened', $mr->status);
    }

    public function testParseMergeRequestDraft(): void
    {
        $payload = [
            'pull_request' => [
                'number' => 123,
                'title' => 'Test PR',
                'body' => 'Test description',
                'state' => 'open',
                'draft' => true,
                'html_url' => 'https://github.com/test/repo/pull/123',
                'head' => ['ref' => 'feature'],
                'base' => ['ref' => 'main'],
                'user' => ['login' => 'testuser'],
            ],
            'repository' => [
                'full_name' => 'test/repo',
            ],
        ];

        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $mr = $this->service->parseMergeRequest($request);

        $this->assertEquals('draft', $mr->status);
    }

    public function testParseMergeRequestInvalidJson(): void
    {
        $request = new Request([], [], [], [], [], [], 'invalid json');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid JSON payload');

        $this->service->parseMergeRequest($request);
    }

    public function testParseMergeRequestNotPullRequestEvent(): void
    {
        $payload = ['action' => 'push'];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->expectExceptionMessage('Not a pull request event');

        $this->service->parseMergeRequest($request);
    }
}