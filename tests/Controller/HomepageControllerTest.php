<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class HomepageControllerTest extends WebTestCase
{
    public function testHomepage(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        
        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Documentation Impact Analyzer', $content);
        $this->assertStringContainsString('AI-powered tool', $content);
        $this->assertStringContainsString('Quick Start', $content);
        $this->assertStringContainsString('Features', $content);
    }

    public function testHealthCheck(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        
        $response = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertEquals('healthy', $response['status']);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertArrayHasKey('version', $response);
        $this->assertArrayHasKey('services', $response);
        $this->assertEquals('connected', $response['services']['database']);
        $this->assertEquals('configured', $response['services']['ai']);
        $this->assertEquals('ready', $response['services']['webhooks']);
    }

    public function testHomepageContainsProjectInformation(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/');

        $content = $client->getResponse()->getContent();
        
        // Check for key sections
        $this->assertStringContainsString('✨ Features', $content);
        $this->assertStringContainsString('🚀 Quick Start', $content);
        $this->assertStringContainsString('🔧 Configuration', $content);
        $this->assertStringContainsString('🏗️ Architecture', $content);
        $this->assertStringContainsString('🧪 Testing', $content);
        
        // Check for technical details
        $this->assertStringContainsString('Symfony 7.3', $content);
        $this->assertStringContainsString('PHP 8.3', $content);
        $this->assertStringContainsString('OpenAI', $content);
        $this->assertStringContainsString('webhook', $content);
    }

    public function testHomepageHasProperMetadata(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/');

        $content = $client->getResponse()->getContent();
        
        // Check meta tags
        $this->assertStringContainsString('<meta name="description"', $content);
        $this->assertStringContainsString('<meta name="keywords"', $content);
        $this->assertStringContainsString('viewport', $content);
        
        // Check title and icon
        $this->assertStringContainsString('Documentation Impact Analyzer', $content);
        $this->assertStringContainsString('📚', $content);
    }
}