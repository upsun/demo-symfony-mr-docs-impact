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
        $comment = $this->formatComment($impact);
        
        $apiUrl = $this->buildCommentApiUrl($mr);
        
        if (!$apiUrl) {
            $this->logger->error('Cannot construct comment API URL', [
                'pr_number' => $mr->id,
            ]);
            return;
        }

        try {
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

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->logger->info('Successfully posted comment to GitHub PR', [
                    'pr_number' => $mr->id,
                ]);
            } else {
                $this->logger->error('Failed to post comment to GitHub PR', [
                    'pr_number' => $mr->id,
                    'status_code' => $response->getStatusCode(),
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Exception while posting comment to GitHub PR', [
                'pr_number' => $mr->id,
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

    private function extractChangedFiles(array $pr): array
    {
        // GitHub PR webhook includes changed files count but not the file list
        // We would need to make an additional API call to get the files
        // For now, return empty array and rely on diff parsing
        return [];
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

    private function formatComment(DocumentationImpact $impact): string
    {
        $emoji = $impact->level->getEmoji();
        $level = $impact->level->getDisplayName();
        
        $comment = "## {$emoji} Documentation Impact Analysis\n\n";
        $comment .= "**Impact Level:** {$level}\n";
        $comment .= "**Documentation Required:** " . ($impact->required ? 'Yes' : 'No') . "\n\n";

        if (!empty($impact->impactedAreas)) {
            $comment .= "**Impacted Areas:**\n";
            foreach ($impact->impactedAreas as $area) {
                $comment .= "- " . ucfirst(str_replace('_', ' ', $area)) . "\n";
            }
            $comment .= "\n";
        }

        if (!empty($impact->reasons)) {
            $comment .= "**Reasons:**\n";
            foreach ($impact->reasons as $reason) {
                $comment .= "- {$reason}\n";
            }
            $comment .= "\n";
        }

        if (!empty($impact->suggestions)) {
            $comment .= "**Suggestions:**\n";
            foreach ($impact->suggestions as $suggestion) {
                $comment .= "- {$suggestion}\n";
            }
            $comment .= "\n";
        }

        $comment .= "---\n";
        $comment .= "*This analysis was generated automatically by the Documentation Impact Analyzer.*";

        return $comment;
    }
}