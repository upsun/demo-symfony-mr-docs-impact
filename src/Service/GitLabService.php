<?php

namespace App\Service;

use App\Model\DocumentationImpact;
use App\Model\MergeRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitLabService implements GitProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiToken,
        private string $webhookSecret,
        private LoggerInterface $logger,
        private CommentRenderer $commentRenderer,
    ) {}

    public function validateWebhook(Request $request): bool
    {
        $signature = $request->headers->get('X-Gitlab-Token');
        
        if (!$signature) {
            $this->logger->warning('Missing GitLab webhook token');
            return false;
        }

        if (!hash_equals($this->webhookSecret, $signature)) {
            $this->logger->warning('Invalid GitLab webhook signature', [
                'ip' => $request->getClientIp(),
            ]);
            return false;
        }

        return true;
    }

    public function parseMergeRequest(Request $request): MergeRequest
    {
        $payload = json_decode($request->getContent(), true);
        
        if (!$payload) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        // Check if this is a merge request event
        if (!isset($payload['object_kind']) || $payload['object_kind'] !== 'merge_request') {
            throw new BadRequestHttpException('Not a merge request event');
        }

        $mrData = $payload['object_attributes'] ?? [];
        $project = $payload['project'] ?? [];
        
        if (!$mrData || !$project) {
            throw new BadRequestHttpException('Missing merge request or project data');
        }

        return new MergeRequest(
            id: (string) $mrData['iid'],
            title: $mrData['title'] ?? '',
            description: $mrData['description'] ?? '',
            sourceBranch: $mrData['source_branch'] ?? '',
            targetBranch: $mrData['target_branch'] ?? '',
            author: $mrData['author']['name'] ?? 'Unknown',
            url: $mrData['url'] ?? '',
            changedFiles: $this->extractChangedFiles($payload),
            status: $mrData['state'] ?? 'unknown',
            diffUrl: $this->buildDiffApiUrl($project, $mrData),
        );
    }

    public function fetchMergeRequestDiff(MergeRequest $mr): string
    {
        if (!$mr->diffUrl) {
            $this->logger->error('No diff URL available for merge request', [
                'mr_id' => $mr->id,
            ]);
            return '';
        }

        try {
            $response = $this->httpClient->request('GET', $mr->diffUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]);

            $changes = $response->toArray();
            
            return $this->formatDiffFromChanges($changes);

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch merge request diff', [
                'mr_id' => $mr->id,
                'error' => $e->getMessage(),
            ]);
            
            return '';
        }
    }

    public function postComment(MergeRequest $mr, DocumentationImpact $impact): void
    {
        $comment = $this->commentRenderer->renderDocumentationImpact($impact, $mr);
        
        // Extract project ID and MR IID from the diff URL or construct API URL
        $apiUrl = $this->buildCommentApiUrl($mr);
        
        if (!$apiUrl) {
            $this->logger->error('Cannot construct comment API URL', [
                'mr_id' => $mr->id,
            ]);
            return;
        }

        try {
            $response = $this->httpClient->request('POST', $apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'body' => $comment,
                ],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->logger->info('Successfully posted comment to GitLab MR', [
                    'mr_id' => $mr->id,
                ]);
            } else {
                $this->logger->error('Failed to post comment to GitLab MR', [
                    'mr_id' => $mr->id,
                    'status_code' => $response->getStatusCode(),
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception while posting comment to GitLab MR', [
                'mr_id' => $mr->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getMergeRequestDetails(MergeRequest $mr): MergeRequest
    {
        // For now, return the existing MR as we have all needed details from webhook
        // In the future, this could fetch additional details from the API
        return $mr;
    }

    private function extractChangedFiles(array $payload): array
    {
        // GitLab webhook doesn't always include changed files in the payload
        // We'll extract them from the changes if available
        $changes = $payload['changes'] ?? [];
        $files = [];

        // Look for file changes in the payload
        if (isset($changes['updated_at'])) {
            // This is an update event, we'd need to fetch the diff to get file list
            // For now, return empty array and rely on the diff fetching
        }

        return $files;
    }

    private function buildDiffApiUrl(array $project, array $mrData): ?string
    {
        $projectId = $project['id'] ?? null;
        $mrIid = $mrData['iid'] ?? null;
        
        if (!$projectId || !$mrIid) {
            return null;
        }

        // Construct GitLab API URL for merge request changes
        $baseUrl = rtrim($project['web_url'] ?? '', '/');
        $baseUrl = str_replace($project['path_with_namespace'] ?? '', '', $baseUrl);
        
        return $baseUrl . "/api/v4/projects/{$projectId}/merge_requests/{$mrIid}/changes";
    }

    private function buildCommentApiUrl(MergeRequest $mr): ?string
    {
        // Extract project info from the MR URL or diff URL
        if (!$mr->diffUrl) {
            return null;
        }

        // Parse the diff URL to get project ID and MR IID
        if (preg_match('/projects\/(\d+)\/merge_requests\/(\d+)/', $mr->diffUrl, $matches)) {
            $projectId = $matches[1];
            $mrIid = $matches[2];
            
            $baseUrl = parse_url($mr->diffUrl, PHP_URL_SCHEME) . '://' . parse_url($mr->diffUrl, PHP_URL_HOST);
            return $baseUrl . "/api/v4/projects/{$projectId}/merge_requests/{$mrIid}/notes";
        }

        return null;
    }

    private function formatDiffFromChanges(array $changes): string
    {
        if (!isset($changes['changes'])) {
            return '';
        }

        $diff = '';
        foreach ($changes['changes'] as $change) {
            $diff .= "--- {$change['old_path']}\n";
            $diff .= "+++ {$change['new_path']}\n";
            $diff .= $change['diff'] ?? '';
            $diff .= "\n\n";
        }

        return $diff;
    }

}