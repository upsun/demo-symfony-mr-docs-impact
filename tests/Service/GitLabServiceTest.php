<?php

namespace App\Tests\Service;

use App\Service\GitLabService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitLabServiceTest extends TestCase
{
    private GitLabService $service;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->service = new GitLabService(
            $this->httpClient,
            'test-token',
            'test-secret',
            $this->logger
        );
    }

    public function testValidateWebhookSuccess(): void
    {
        $request = new Request([], [], [], [], [], [
            'HTTP_X_GITLAB_TOKEN' => 'test-secret',
        ]);

        $this->assertTrue($this->service->validateWebhook($request));
    }

    public function testValidateWebhookMissingToken(): void
    {
        $request = new Request();

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Missing GitLab webhook token');

        $this->assertFalse($this->service->validateWebhook($request));
    }

    public function testValidateWebhookInvalidToken(): void
    {
        $request = new Request([], [], [], [], [], [
            'HTTP_X_GITLAB_TOKEN' => 'wrong-secret',
        ]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Invalid GitLab webhook signature', $this->anything());

        $this->assertFalse($this->service->validateWebhook($request));
    }

    public function testParseMergeRequestSuccess(): void
    {
        $payload = [
            'object_kind' => 'merge_request',
            'object_attributes' => [
                'iid' => 123,
                'title' => 'Test MR',
                'description' => 'Test description',
                'source_branch' => 'feature',
                'target_branch' => 'main',
                'state' => 'opened',
                'url' => 'https://gitlab.com/test/repo/-/merge_requests/123',
                'author' => ['name' => 'Test User'],
            ],
            'project' => [
                'id' => 456,
                'web_url' => 'https://gitlab.com/test/repo',
                'path_with_namespace' => 'test/repo',
            ],
        ];

        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $mr = $this->service->parseMergeRequest($request);

        $this->assertEquals('123', $mr->id);
        $this->assertEquals('Test MR', $mr->title);
        $this->assertEquals('Test description', $mr->description);
        $this->assertEquals('feature', $mr->sourceBranch);
        $this->assertEquals('main', $mr->targetBranch);
        $this->assertEquals('Test User', $mr->author);
        $this->assertEquals('opened', $mr->status);
    }

    public function testParseMergeRequestInvalidJson(): void
    {
        $request = new Request([], [], [], [], [], [], 'invalid json');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid JSON payload');

        $this->service->parseMergeRequest($request);
    }

    public function testParseMergeRequestNotMergeRequestEvent(): void
    {
        $payload = ['object_kind' => 'push'];
        $request = new Request([], [], [], [], [], [], json_encode($payload));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->expectExceptionMessage('Not a merge request event');

        $this->service->parseMergeRequest($request);
    }
}