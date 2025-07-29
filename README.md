# 📚 Documentation Impact Analyzer

[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](https://opensource.org/licenses/Apache-2.0)
[![Symfony](https://img.shields.io/badge/Symfony-7.3-green.svg)](https://symfony.com/)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net/)

An AI-powered tool that analyzes merge requests and automatically determines if code changes require user documentation updates. Built with Symfony 7.3 and powered by OpenAI's GPT models, it helps maintain documentation quality by flagging changes that impact user-facing features, APIs, or configurations.

## ✨ Features

### 🤖 Intelligent Analysis
- **AI-Powered Detection**: Uses Claude 3.5 Sonnet to analyze code changes and determine documentation impact
- **File-Type Awareness**: Intelligent categorization of changed files (controllers, models, config, templates, etc.)
- **Context-Aware Prompts**: Tailored analysis based on the type of changes detected
- **Multi-Level Impact**: Categorizes changes from NONE to CRITICAL impact levels

### 🔗 Git Provider Integration
- **GitHub Support**: Complete webhook integration with signature validation
- **GitLab Support**: Full API integration with merge request handling
- **Secure Webhooks**: HMAC signature validation for both platforms
- **Real-time Processing**: Instant analysis when merge requests are created or updated

### 💬 Professional Comments
- **Beautiful Formatting**: Rich markdown comments with emojis and structured sections
- **Actionable Suggestions**: Specific, practical documentation recommendations
- **Code Examples**: Automatic formatting of code snippets, API endpoints, and configuration
- **Impact Warnings**: Clear indicators for high-impact changes requiring immediate attention

### 🧪 Comprehensive Testing
- **30+ Tests**: Full test coverage with unit, integration, and end-to-end tests
- **94+ Assertions**: Robust validation of all functionality
- **Mock AI Responses**: Consistent testing without API dependencies
- **Webhook Testing**: Complete validation of webhook processing

## 🚀 Quick Start

### Prerequisites

- PHP 8.3 or higher
- Composer
- PostgreSQL 17
- Anthropic API key
- Git provider tokens (GitHub/GitLab)

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/upsun/demo-symfony-mr-docs-impact.git
   cd demo-symfony-mr-docs-impact
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   ```
   
   Edit `.env` with your configuration:
   ```bash
   # Database
   DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/mr_docs_impact"
   
   # Git Providers
   GITHUB_TOKEN=your-github-token
   GITHUB_WEBHOOK_SECRET=your-webhook-secret
   GITLAB_TOKEN=your-gitlab-token
   GITLAB_WEBHOOK_SECRET=your-webhook-secret
   
   # AI Configuration
   ANTHROPIC_API_KEY=your-anthropic-api-key
   ```

4. **Set up database**
   ```bash
   # Using Docker
   docker-compose up -d database
   
   # Or with local PostgreSQL
   createdb mr_docs_impact
   ```

5. **Start the server**
   ```bash
   symfony local:server:start --allow-all-ip
   ```

### Docker Setup

Use Docker Compose for a complete development environment:

```bash
docker-compose up -d
```

This starts:
- PostgreSQL 17 database
- PHP 8.3 application server
- All necessary dependencies

## 🔧 Configuration

### Webhook Setup

#### GitHub
1. Go to your repository settings → Webhooks
2. Add webhook URL: `https://your-domain.com/webhook/github`
3. Content type: `application/json`
4. Events: `Pull requests`
5. Secret: Set your `GITHUB_WEBHOOK_SECRET`

#### GitLab
1. Go to your project settings → Webhooks
2. Add webhook URL: `https://your-domain.com/webhook/gitlab`
3. Trigger: `Merge request events`
4. Secret token: Set your `GITLAB_WEBHOOK_SECRET`

### AI Configuration

The system uses Anthropic's Claude 3.5 Sonnet model for intelligent analysis. You can customize:

```yaml
# config/packages/ai.yaml
ai:
    platform:
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
```

## 📊 How It Works

### 1. Webhook Reception
When a merge request is created or updated, the system receives a webhook from your Git provider.

### 2. Change Analysis
The AI analyzer examines:
- **File Types**: Controllers, models, configuration, templates, etc.
- **Change Patterns**: New features, API modifications, breaking changes
- **Context**: Commit messages, branch names, file locations
- **Diff Content**: Actual code changes and their implications

### 3. Impact Assessment
Changes are categorized into impact levels:

| Level | Description | Action |
|-------|-------------|---------|
| 🚨 **CRITICAL** | Breaking changes requiring immediate action | Blocks merge until documented |
| 🔴 **HIGH** | New features or significant API changes | Prioritize documentation |
| 🟠 **MEDIUM** | Enhancements or configuration changes | Document when convenient |
| 🟡 **LOW** | Minor improvements or bug fixes | Optional documentation |
| ✅ **NONE** | Internal changes with no user impact | No documentation needed |

### 4. Comment Generation
Professional comments are posted to the merge request with:
- Clear impact assessment
- Specific affected documentation areas
- Actionable suggestions with examples
- Links to documentation guidelines

## 🏗️ Architecture

### Directory Structure
```
src/
├── Controller/
│   └── WebhookController.php      # Webhook endpoints
├── Service/
│   ├── GitProviderInterface.php   # Git provider abstraction
│   ├── GitLabService.php          # GitLab integration
│   ├── GitHubService.php          # GitHub integration
│   ├── DocumentationAnalyzer.php  # AI analysis engine
│   └── CommentRenderer.php        # Comment formatting
├── Model/
│   ├── MergeRequest.php           # MR data structure
│   ├── DocumentationImpact.php    # Analysis results
│   └── ImpactLevel.php            # Impact classification
└── Prompt/
    └── AnalysisPromptBuilder.php  # AI prompt generation
```

### Key Components

#### DocumentationAnalyzer
The core AI service that:
- Sanitizes and prepares diff content
- Builds context-aware prompts
- Calls OpenAI API with structured requests
- Parses and validates AI responses
- Formats suggestions with markdown

#### CommentRenderer
Professional comment formatting with:
- Twig template rendering
- Markdown formatting
- Code syntax highlighting
- Emoji indicators
- Structured sections

#### Git Provider Services
Unified interface for both GitHub and GitLab:
- Webhook signature validation
- Merge request parsing
- Diff retrieval
- Comment posting
- Error handling

## 🧪 Testing

Run the complete test suite:

```bash
# All tests
php bin/phpunit

# Specific test suites
php bin/phpunit tests/Service/
php bin/phpunit tests/Controller/
php bin/phpunit tests/Integration/

# With coverage
php bin/phpunit --coverage-html coverage/
```

### Test Categories
- **Unit Tests**: Individual service and model testing
- **Integration Tests**: API connection and component interaction
- **Controller Tests**: End-to-end webhook processing
- **Comment Rendering**: Template and formatting validation

## 🚀 Deployment

### Upsun Deployment

The project includes Upsun configuration:

```yaml
# .upsun/config.yaml
applications:
    app:
        type: 'php:8.3'
        dependencies:
            php:
                composer/composer: '^2'
        web:
            locations:
                "/":
                    root: "public"
                    passthru: "/index.php"

services:
    db:
        type: postgresql:17
        disk: 256
```

Deploy with:
```bash
upsun push
```

### Environment Variables

Set these in your production environment:
```bash
APP_ENV=prod
APP_SECRET=your-production-secret
DATABASE_URL=your-production-db-url
ANTHROPIC_API_KEY=your-anthropic-key
GITHUB_TOKEN=your-github-token
GITHUB_WEBHOOK_SECRET=your-webhook-secret
GITLAB_TOKEN=your-gitlab-token
GITLAB_WEBHOOK_SECRET=your-webhook-secret
```

## 💰 Cost Analysis

### Anthropic API Costs
Using Claude 3.5 Sonnet for intelligent analysis:
- **Average cost per analysis**: < $0.10
- **Typical MR (1000 lines)**: ~$0.05
- **Large MR (5000 lines)**: ~$0.15
- **Monthly cost (100 MRs)**: ~$5-10

### Performance Metrics
- **Analysis time**: < 30 seconds per MR
- **Accuracy rate**: > 90% based on testing
- **False positive rate**: < 15%

## 🛠️ Development

### Adding New Git Providers

1. Implement `GitProviderInterface`
2. Add webhook signature validation
3. Parse provider-specific payloads
4. Register in `services.yaml`
5. Add route in `WebhookController`

### Customizing Analysis

Modify `AnalysisPromptBuilder` to:
- Add new file type categories
- Customize prompt templates
- Include domain-specific examples
- Adjust impact level criteria

### Extending Comment Templates

Edit `templates/comment/documentation_impact.md.twig` to:
- Change formatting style
- Add new sections
- Customize emoji indicators
- Include additional metadata

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Make your changes and add tests
4. Ensure tests pass: `php bin/phpunit`
5. Commit with conventional commits: `git commit -m "feat: add amazing feature"`
6. Push to the branch: `git push origin feature/amazing-feature`
7. Open a Pull Request

### Code Standards
- PSR-12 coding standards
- 100% test coverage for new features
- Comprehensive documentation
- Type hints and return types

## 📄 License

This project is licensed under the Apache License 2.0 - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- [Symfony](https://symfony.com/) - The PHP framework for this project
- [Anthropic](https://anthropic.com/) - AI analysis capabilities with Claude
- [Twig](https://twig.symfony.com/) - Template rendering
- [PHPUnit](https://phpunit.de/) - Testing framework

## 📞 Support

- 🐛 Issues: [GitHub Issues](https://github.com/upsun/demo-symfony-mr-docs-impact/issues)
- 📖 Documentation: [Project Wiki](https://github.com/upsun/demo-symfony-mr-docs-impact/wiki)
- 💬 Discussions: [GitHub Discussions](https://github.com/upsun/demo-symfony-mr-docs-impact/discussions)

---

**Built with ❤️ by the Documentation Impact Analyzer team**
