<?php

namespace App\Controller;

use League\CommonMark\CommonMarkConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomepageController extends AbstractController
{
    #[Route('/', name: 'homepage')]
    public function index(): Response
    {
        // Read the README.md file
        $readmePath = $this->getParameter('kernel.project_dir') . '/README.md';
        
        if (!file_exists($readmePath)) {
            throw $this->createNotFoundException('README.md file not found');
        }
        
        $readmeContent = file_get_contents($readmePath);
        
        // Convert Markdown to HTML
        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);
        
        $htmlContent = $converter->convert($readmeContent);

        return $this->render('homepage/index.html.twig', [
            'readme_content' => $htmlContent,
            'project_name' => 'Documentation Impact Analyzer',
        ]);
    }

    #[Route('/health', name: 'health_check')]
    public function healthCheck(): Response
    {
        return $this->json([
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => '1.0.0',
            'services' => [
                'database' => 'connected',
                'ai' => 'configured',
                'webhooks' => 'ready',
            ]
        ]);
    }
}