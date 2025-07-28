<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class WebhookControllerTest extends WebTestCase
{
    public function testGitLabWebhookUnauthorized(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/webhook/gitlab', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['test' => 'data']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGitHubWebhookUnauthorized(): void
    {
        $client = static::createClient();
        
        $client->request('POST', '/webhook/github', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['test' => 'data']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGitLabWebhookWithValidSignature(): void
    {
        $client = static::createClient();
        
        $payload = [
            'object_kind' => 'merge_request',
            'object_attributes' => [
                'iid' => 123,
                'title' => '[WIP] Test MR',
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

        // Set test webhook secret in environment
        $_ENV['GITLAB_WEBHOOK_SECRET'] = 'test-secret';
        
        $client->request('POST', '/webhook/gitlab', [], [], [
            'HTTP_X_GITLAB_TOKEN' => 'test-secret',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        // Should skip WIP merge requests
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertStringContainsString('Skipped', $client->getResponse()->getContent());
    }

    public function testGitHubWebhookWithValidSignature(): void
    {
        $client = static::createClient();
        
        $payload = [
            'pull_request' => [
                'number' => 123,
                'title' => 'Draft: Test PR',
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

        $payloadJson = json_encode($payload);
        $_ENV['GITHUB_WEBHOOK_SECRET'] = 'test-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $payloadJson, 'test-secret');
        
        $client->request('POST', '/webhook/github', [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payloadJson);

        // Should skip draft pull requests
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertStringContainsString('Skipped', $client->getResponse()->getContent());
    }

    public function testInvalidWebhookPayload(): void
    {
        $client = static::createClient();
        
        $_ENV['GITLAB_WEBHOOK_SECRET'] = 'test-secret';
        
        $client->request('POST', '/webhook/gitlab', [], [], [
            'HTTP_X_GITLAB_TOKEN' => 'test-secret',
            'CONTENT_TYPE' => 'application/json',
        ], 'invalid json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testNonMergeRequestEvent(): void
    {
        $client = static::createClient();
        
        $payload = ['object_kind' => 'push'];
        $_ENV['GITLAB_WEBHOOK_SECRET'] = 'test-secret';
        
        $client->request('POST', '/webhook/gitlab', [], [], [
            'HTTP_X_GITLAB_TOKEN' => 'test-secret',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }
}