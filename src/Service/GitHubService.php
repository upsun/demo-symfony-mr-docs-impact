<?php

namespace App\Service;

use App\Model\DocumentationImpact;
use App\Model\MergeRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GitHubService implements GitProviderInterface
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
        $signature = $request->headers->get('X-Hub-Signature-256');
        
        if (!$signature) {
            $this->logger->warning('Missing GitHub webhook signature');
            return false;
        }

        $payload = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $this->webhookSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            $this->logger->warning('Invalid GitHub webhook signature', [
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

        // Check if this is a pull request event
        if (!isset($payload['pull_request'])) {
            throw new BadRequestHttpException('Not a pull request event');
        }

        $pr = $payload['pull_request'];
        $repository = $payload['repository'] ?? [];
        
        return new MergeRequest(
            id: (string) $pr['number'],
            title: $pr['title'] ?? '',
            description: $pr['body'] ?? '',
            sourceBranch: $pr['head']['ref'] ?? '',
            targetBranch: $pr['base']['ref'] ?? '',
            author: $pr['user']['login'] ?? 'Unknown',
            url: $pr['html_url'] ?? '',
            changedFiles: $this->extractChangedFiles($pr),
            status: $this->mapPrState($pr),
            diffUrl: $this->buildDiffApiUrl($repository, $pr),
        );
    }

    public function fetchMergeRequestDiff(MergeRequest $mr): string
    {
        if (!$mr->diffUrl) {
            $this->logger->error('No diff URL available for pull request', [
                'pr_number' => $mr->id,
            ]);
            return '';
        }

        try {
            $response = $this->httpClient->request('GET', $mr->diffUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Accept' => 'application/vnd.github.v3.diff',
                    'User-Agent' => 'Documentation-Impact-Analyzer/1.0',
                ],
                'timeout' => 30,
            ]);

            return $response->getContent();

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch pull request diff', [
                'pr_number' => $mr->id,
                'error' => $e->getMessage(),
            ]);
            
            return '';
        }
    }

    public function postComment(MergeRequest $mr, DocumentationImpact $impact): void
    {
        $this->logger->info('Starting postComment process', [
            'pr_number' => $mr->id,
            'pr_url' => $mr->url,
            'impact_level' => $impact->level->value,
            'should_comment' => $impact->shouldComment(),
        ]);
        
        $comment = $this->commentRenderer->renderDocumentationImpact($impact, $mr);
        
        $this->logger->debug('Comment rendered', [
            'pr_number' => $mr->id,
            'comment_length' => strlen($comment),
        ]);
        
        $apiUrl = $this->buildCommentApiUrl($mr);
        
        $this->logger->info('API URL constructed', [
            'pr_number' => $mr->id,
            'api_url' => $apiUrl,
            'url_valid' => !empty($apiUrl),
        ]);
        
        if (!$apiUrl) {
            $this->logger->error('Cannot construct comment API URL', [
                'pr_number' => $mr->id,
                'mr_url' => $mr->url,
            ]);
            return;
        }

        try {
            $this->logger->info('Making API request to post comment', [
                'pr_number' => $mr->id,
                'api_url' => $apiUrl,
                'has_token' => !empty($this->apiToken),
                'token_length' => strlen($this->apiToken),
            ]);
            
            $response = $this->httpClient->request('POST', $apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Accept' => 'application/vnd.github.v3+json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Documentation-Impact-Analyzer/1.0',
                ],
                'json' => [
                    'body' => $comment,
                ],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            $responseContent = $response->getContent(false);
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Successfully posted comment to GitHub PR', [
                    'pr_number' => $mr->id,
                    'status_code' => $statusCode,
                ]);
            } else {
                $this->logger->error('Failed to post comment to GitHub PR', [
                    'pr_number' => $mr->id,
                    'status_code' => $statusCode,
                    'response_body' => $responseContent,
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception while posting comment to GitHub PR', [
                'pr_number' => $mr->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function getMergeRequestDetails(MergeRequest $mr): MergeRequest
    {
        // For now, return the existing MR as we have all needed details from webhook
        // In the future, this could fetch additional details from the API
        return $mr;
    }

    private function extractChangedFiles(array $pr): array
    {
        // GitHub PR webhook includes changed files count but not the file list
        // We need to make an additional API call to get the files
        $repository = $pr['base']['repo'] ?? [];
        $repoFullName = $repository['full_name'] ?? null;
        $prNumber = $pr['number'] ?? null;
        
        if (!$repoFullName || !$prNumber) {
            $this->logger->warning('Cannot extract changed files - missing repo or PR info');
            return ['unknown']; // Return non-empty array to allow analysis
        }

        try {
            $response = $this->httpClient->request('GET', "https://api.github.com/repos/{$repoFullName}/pulls/{$prNumber}/files", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'Documentation-Impact-Analyzer/1.0',
                ],
                'timeout' => 10,
            ]);

            $files = json_decode($response->getContent(), true);
            
            if (!is_array($files)) {
                return ['unknown'];
            }
            
            return array_map(fn($file) => $file['filename'] ?? 'unknown', $files);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch changed files from GitHub API', [
                'pr_number' => $prNumber,
                'error' => $e->getMessage(),
            ]);
            
            // Return non-empty array to allow analysis to proceed
            return ['unknown'];
        }
    }

    private function mapPrState(array $pr): string
    {
        $state = $pr['state'] ?? 'unknown';
        $draft = $pr['draft'] ?? false;
        
        if ($draft) {
            return 'draft';
        }
        
        return match($state) {
            'open' => 'opened',
            'closed' => $pr['merged'] ?? false ? 'merged' : 'closed',
            default => $state,
        };
    }

    private function buildDiffApiUrl(array $repository, array $pr): ?string
    {
        $repoFullName = $repository['full_name'] ?? null;
        $prNumber = $pr['number'] ?? null;
        
        if (!$repoFullName || !$prNumber) {
            return null;
        }

        return "https://api.github.com/repos/{$repoFullName}/pulls/{$prNumber}";
    }

    private function buildCommentApiUrl(MergeRequest $mr): ?string
    {
        // Extract repo info from the MR URL
        if (preg_match('/github\.com\/([^\/]+\/[^\/]+)\/pull\/(\d+)/', $mr->url, $matches)) {
            $repoFullName = $matches[1];
            return "https://api.github.com/repos/{$repoFullName}/issues/{$mr->id}/comments";
        }

        return null;
    }

}