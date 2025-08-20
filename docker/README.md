# Docker Setup for NullFake

This directory contains an **optional** Docker setup for running NullFake. Your existing local development environment is not affected by these files.

## Quick Start

1. **Copy environment file:**
   ```bash
   cp docker.env.example .env
   ```

2. **Start the containers:**
   ```bash
   docker-compose -f docker/docker-compose.yml up -d
   ```

3. **Run initial setup:**
   ```bash
   # Generate application key
   docker-compose -f docker/docker-compose.yml exec app php artisan key:generate
   
   # Run migrations
   docker-compose -f docker/docker-compose.yml exec app php artisan migrate
   
   # Install Ollama model (optional, for local AI)
   docker-compose -f docker/docker-compose.yml exec ollama ollama pull phi4:14b
   ```

4. **Access the application:**
   - Web interface: http://localhost:8080
   - Database: localhost:3307 (user: faker, password: password)
   - Ollama API: http://localhost:11434

## Services Included

- **app**: Laravel application (PHP 8.3-FPM)
- **nginx**: Web server
- **db**: MariaDB 10.11 database
- **queue**: Queue worker for async processing
- **ollama**: Local AI model server (optional)

## Configuration

The Docker setup uses environment variables for configuration. This follows Docker best practices:

### **Environment File Strategy:**
- ✅ **`docker.env.example`** - Safe defaults template (committed to git)
- ✅ **`.env`** - Your customized config (NOT committed to git)
- ✅ **Secrets handled externally** - API keys provided by user

### **Required Configuration:**
1. **Copy the template:**
   ```bash
   cp docker.env.example .env
   ```

2. **Configure at least one LLM provider in `.env`:**
   ```bash
   # Option 1: OpenAI (most reliable)
   OPENAI_API_KEY=sk-proj-your-actual-key-here
   LLM_PRIMARY_PROVIDER=openai
   
   # Option 2: Local Ollama (free)
   LLM_PRIMARY_PROVIDER=ollama
   # (Ollama runs in Docker, no API key needed)
   ```

### **Optional Configuration:**
- External API keys (BrightData, DeepSeek)
- Amazon affiliate tags
- Email/monitoring settings
- Port numbers (if conflicts occur)

## Useful Commands

```bash
# View logs
docker-compose -f docker/docker-compose.yml logs -f

# Access application container
docker-compose -f docker/docker-compose.yml exec app bash

# Run artisan commands
docker-compose -f docker/docker-compose.yml exec app php artisan [command]

# Stop containers
docker-compose -f docker/docker-compose.yml down

# Stop and remove volumes (WARNING: deletes data)
docker-compose -f docker/docker-compose.yml down -v
```

## Development vs Production

This Docker setup is configured for development/testing. For production deployment:

1. Set `APP_ENV=production` and `APP_DEBUG=false`
2. Use proper SSL certificates
3. Configure proper backup strategies
4. Use external managed services for database/redis in production
5. Set up proper monitoring and logging

## Troubleshooting

- **Port conflicts**: Change ports in docker-compose.yml if 8080, 3307, or 11434 are already in use
- **Permission issues**: Run `docker-compose -f docker/docker-compose.yml exec app chown -R www-data:www-data /var/www/html`
- **Database connection**: Ensure DB_HOST=db in your .env file
- **Queue not processing**: Check queue worker logs with `docker-compose -f docker/docker-compose.yml logs queue`
- **MariaDB issues**: MariaDB is compatible with MySQL, no configuration changes needed
