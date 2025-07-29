<?php

namespace App\Controller;

use App\Service\GitProviderInterface;
use App\Service\DocumentationAnalyzer;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends AbstractController
{
    public function __construct(
        private readonly DocumentationAnalyzer $analyzer,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/webhook/github', name: 'webhook_github', methods: ['POST'])]
    public function handleGitHubWebhook(
        Request $request,
        GitProviderInterface $gitHubProvider
    ): Response {
        return $this->handleWebhook('github', $request, $gitHubProvider);
    }

    #[Route('/webhook/gitlab', name: 'webhook_gitlab', methods: ['POST'])]
    public function handleGitLabWebhook(
        Request $request,
        GitProviderInterface $gitLabProvider
    ): Response {
        return $this->handleWebhook('gitlab', $request, $gitLabProvider);
    }

    private function handleWebhook(
        string $provider, 
        Request $request, 
        GitProviderInterface $gitProvider
    ): Response {
        $this->logger->info('Received webhook', [
            'provider' => $provider,
            'user_agent' => $request->headers->get('User-Agent'),
            'content_type' => $request->headers->get('Content-Type'),
        ]);

        try {
            // 1. Validate webhook signature
            if (!$gitProvider->validateWebhook($request)) {
                $this->logger->warning('Invalid webhook signature', [
                    'provider' => $provider,
                    'ip' => $request->getClientIp(),
                ]);
                throw new UnauthorizedHttpException('', 'Invalid webhook signature');
            }

            // 2. Extract MR data
            $mergeRequest = $gitProvider->parseMergeRequest($request);
            
            $this->logger->info('Parsed merge request', [
                'provider' => $provider,
                'mr_id' => $mergeRequest->id,
                'title' => $mergeRequest->title,
                'author' => $mergeRequest->author,
                'status' => $mergeRequest->status,
            ]);

            // 3. Skip if not relevant (draft, WIP, closed)
            if (!$this->shouldAnalyze($mergeRequest)) {
                $this->logger->info('Skipping analysis', [
                    'provider' => $provider,
                    'mr_id' => $mergeRequest->id,
                    'reason' => 'Draft, WIP, or closed',
                ]);
                return new Response('Skipped - not relevant for analysis', Response::HTTP_OK);
            }

            // 4. Fetch diff
            $diff = $gitProvider->fetchMergeRequestDiff($mergeRequest);
            
            if (empty($diff)) {
                $this->logger->warning('Empty diff received', [
                    'provider' => $provider,
                    'mr_id' => $mergeRequest->id,
                ]);
                return new Response('Skipped - empty diff', Response::HTTP_OK);
            }

            // 5. Analyze with AI
            $impact = $this->analyzer->analyze($mergeRequest, $diff);
            
            $this->logger->info('Analysis completed', [
                'provider' => $provider,
                'mr_id' => $mergeRequest->id,
                'impact_level' => $impact->level->value,
                'required' => $impact->required,
                'should_comment' => $impact->shouldComment(),
            ]);

            // 6. Post comment if needed
            if ($impact->shouldComment()) {
                $this->logger->info('Attempting to post comment', [
                    'provider' => $provider,
                    'mr_id' => $mergeRequest->id,
                    'impact_level' => $impact->level->value,
                    'mr_url' => $mergeRequest->url,
                ]);
                
                $gitProvider->postComment($mergeRequest, $impact);
                
                $this->logger->info('Comment posted successfully', [
                    'provider' => $provider,
                    'mr_id' => $mergeRequest->id,
                    'impact_level' => $impact->level->value,
                ]);
            } else {
                $this->logger->info('Skipping comment - should not comment', [
                    'provider' => $provider,
                    'mr_id' => $mergeRequest->id,
                    'impact_level' => $impact->level->value,
                    'required' => $impact->required,
                    'should_comment' => $impact->shouldComment(),
                ]);
            }

            return new Response('Processed successfully', Response::HTTP_OK);

        } catch (BadRequestHttpException $e) {
            $this->logger->error('Bad request error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            
            return new Response('Bad request: ' . $e->getMessage(), Response::HTTP_BAD_REQUEST);

        } catch (UnauthorizedHttpException $e) {
            return new Response($e->getMessage(), Response::HTTP_UNAUTHORIZED);

        } catch (\Exception $e) {
            $this->logger->error('Webhook processing error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new Response('Processing error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function shouldAnalyze(\App\Model\MergeRequest $mergeRequest): bool
    {
        // Skip drafts, WIP, or closed MRs
        if ($mergeRequest->isDraftOrWip() || $mergeRequest->isClosed()) {
            return false;
        }

        // Skip if no files changed
        if (empty($mergeRequest->changedFiles)) {
            return false;
        }

        return true;
    }
}