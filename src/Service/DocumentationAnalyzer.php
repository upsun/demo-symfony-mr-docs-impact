<?php

namespace App\Service;

use App\Model\DocumentationImpact;
use App\Model\ImpactLevel;
use App\Model\MergeRequest;
use App\Prompt\AnalysisPromptBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DocumentationAnalyzer
{
    public function __construct(
        private AnalysisPromptBuilder $promptBuilder,
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private string $openaiApiKey,
        private int $maxDiffSize = 50000,
    ) {}

    public function analyze(MergeRequest $mr, string $diff): DocumentationImpact
    {
        $this->logger->info('Starting documentation analysis', [
            'mr_id' => $mr->id,
            'diff_size' => strlen($diff),
        ]);

        try {
            // Sanitize and prepare the diff
            $sanitizedDiff = $this->sanitizeDiff($diff);
            
            if (empty($sanitizedDiff)) {
                $this->logger->warning('Empty diff after sanitization', [
                    'mr_id' => $mr->id,
                ]);
                
                return new DocumentationImpact(
                    level: ImpactLevel::NONE,
                    required: false,
                    impactedAreas: [],
                    reasons: ['No meaningful changes detected'],
                    suggestions: [],
                );
            }

            // Build the prompt
            $prompt = $this->promptBuilder->build($mr, $sanitizedDiff);

            // Call OpenAI API
            $response = $this->callOpenAI($prompt);

            // Parse and return the response
            $impact = $this->parseResponse($response);

            $this->logger->info('Analysis completed successfully', [
                'mr_id' => $mr->id,
                'impact_level' => $impact->level->value,
                'required' => $impact->required,
            ]);

            return $impact;

        } catch (\Exception $e) {
            $this->logger->error('Analysis failed', [
                'mr_id' => $mr->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return safe default
            return DocumentationImpact::createSafeDefault(
                'AI analysis failed: ' . $e->getMessage()
            );
        }
    }

    private function sanitizeDiff(string $diff): string
    {
        // Remove any potential harmful content
        $diff = strip_tags($diff);

        // Limit diff size to prevent token overflow
        if (strlen($diff) > $this->maxDiffSize) {
            $this->logger->info('Truncating large diff', [
                'original_size' => strlen($diff),
                'max_size' => $this->maxDiffSize,
            ]);
            
            $diff = substr($diff, 0, $this->maxDiffSize) . "\n... [truncated due to size]";
        }

        return trim($diff);
    }

    private function callOpenAI(string $prompt): array
    {
        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openaiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a documentation expert analyzing code changes to determine if user documentation needs to be updated. Always respond with valid JSON.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray();

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException('Invalid OpenAI API response structure');
        }

        return json_decode($data['choices'][0]['message']['content'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function parseResponse(array $data): DocumentationImpact
    {
        // Validate required fields
        if (!isset($data['requires_documentation'], $data['impact_level'])) {
            throw new \RuntimeException('Missing required fields in AI response');
        }

        // Validate impact level
        $impactLevel = ImpactLevel::tryFrom($data['impact_level']);
        if (!$impactLevel) {
            $this->logger->warning('Invalid impact level in response', [
                'received_level' => $data['impact_level'],
            ]);
            $impactLevel = ImpactLevel::MEDIUM;
        }

        return new DocumentationImpact(
            level: $impactLevel,
            required: (bool) $data['requires_documentation'],
            impactedAreas: $data['impacted_areas'] ?? [],
            reasons: $data['reasons'] ?? [],
            suggestions: $data['suggestions'] ?? [],
        );
    }
}