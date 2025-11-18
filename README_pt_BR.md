# Egypteam Laravel App Logger (pt-BR)

Um pacote de *logging* estruturado para Laravel, inspirado no padrão Log4j.  
Suporta AWS CloudWatch nativamente e expõe uma API elegante de alto nível via a *facade* `AppLog`.

## Recursos

- API estilo Log4j: `trace`, `debug`, `info`, `warn`, `error`, `fatal`
- Logs estruturados em JSON (prontos para análise em ferramentas de log)
- MDC (Mapped Diagnostic Context) e NDC (Nested Diagnostic Context)
- Transporte para CloudWatch com *batching*, recuperação de *sequence token* e *fallback* local
- Arquitetura agnóstica de provedor (fácil trocar CloudWatch por outro backend)
- Auto-discovery no Laravel (service provider + facade)
- Compatível com Laravel 10 / 11 / 12 e Monolog 3

---

## Instalação

Instale via Composer:

```bash
composer require egypteam/laravel-app-logger
```

O pacote usa auto-discovery do Laravel, então o *service provider* e a *facade* serão registrados automaticamente.

Se quiser publicar o arquivo de configuração:

```bash
php artisan vendor:publish --provider="Egypteam\LaravelAppLogger\LaravelAppLoggerServiceProvider" --tag=config
```

Isso criará o arquivo `config/app-logger.php` no seu projeto.

---

## Configuração

### 1. Canal de Log

No `config/logging.php` da sua aplicação, adicione o canal `app_log`:

```php
'channels' => [
    // ...

    'app_log' => [
        'driver' => 'custom',
        'via'    => \Egypteam\LaravelAppLogger\Logging\AppLoggerService::class,
        'level'  => env('LOG_LEVEL', 'info'),
    ],
],
```

> Você pode manter seus canais padrão do Laravel normalmente. `app_log` é um canal adicional para logs de aplicação estruturados.

### 2. Variáveis de Ambiente (`.env`)

O comportamento principal é controlado via `.env`:

```dotenv
APP_LOG_PROVIDER=cloudwatch

# Configurações CloudWatch
AWS_REGION=us-east-1
CW_LOG_GROUP=my-app
CW_LOG_STREAM=web
CW_RETENTION_DAYS=14
CW_BATCH_SIZE=50
CW_FLUSH_SECONDS=2
CW_FALLBACK_PATH=/var/log/laravel/cloudwatch-fallback.log

# Amostragem (debug)
APP_LOG_SAMPLING_DEBUG=0.10   # mantém ~10% dos logs de debug
```

### 3. Arquivo de Configuração (`config/app-logger.php`)

Após publicar a config, você verá:

- `provider` – backend ativo (por enquanto apenas `cloudwatch`)
- `sampling` – fatores de amostragem por nível
- `providers.cloudwatch` – configuração específica do CloudWatch

Se `fallback_path` for `null`, o pacote usa internamente um caminho padrão (`storage/logs/cloudwatch-fallback.log`).

---

## Uso

### Log básico

```php
use Egypteam\LaravelAppLogger\Facades\AppLog;

AppLog::info('Usuário autenticado', ['user_id' => 42]);

AppLog::warn('Latência alta detectada', [
    'endpoint'    => '/api/orders',
    'duration_ms' => 3500,
]);

AppLog::error('Falha no pagamento', [
    'order_id' => 123,
    'gateway'  => 'stripe',
]);
```

### Usando MDC (mapa de contexto) e NDC (pilha de contexto)

```php
use Egypteam\LaravelAppLogger\Facades\AppLog;

AppLog::withContext(['tenant' => 'acme'])
    ->pushContext('checkout')
    ->info('Pedido processado', ['order_id' => 123]);

AppLog::pushContext('payment')
    ->debug('Tentativa de pagamento', ['amount' => 99.90]);
```

No JSON:

- MDC aparece no campo `mdc` (mapa chave/valor).
- NDC aparece no campo `ndc` (array de escopos).

### Amostragem

Por padrão, apenas o nível `debug` é amostrado:

```dotenv
APP_LOG_SAMPLING_DEBUG=0.05   # mantém ~5% dos logs de debug
```

Os demais níveis (`info`, `warning`, `error`, etc.) usam 100% (valor `1.0`) por padrão.

---

## Formato de Saída

Os logs são enviados como linhas JSON (*JSON Lines*). Exemplo:

```json
{
  "ts": "2025-11-05T13:44:10+00:00",
  "level": "INFO",
  "message": "Order processed",
  "channel": "app_log",
  "env": "production",
  "app": "ExampleApp",
  "host": "web-01",
  "ip": "10.0.1.25",
  "request": {
    "method": "POST",
    "uri": "/api/orders",
    "id": "req-abc123"
  },
  "user_id": 42,
  "mdc": { "tenant": "acme" },
  "ndc": ["checkout"],
  "context": { "order_id": 123 }
}
```

Esse formato é ideal para CloudWatch Logs Insights, Splunk, Datadog, Loki, etc.

---

## Transporte para CloudWatch

A classe `CloudwatchHandler` faz o envio dos eventos para o AWS CloudWatch Logs:

- Agrupa eventos em *batch* (parâmetros `CW_BATCH_SIZE` e `CW_FLUSH_SECONDS`)
- Gerencia o *sequence token* do CloudWatch (`InvalidSequenceTokenException`, `DataAlreadyAcceptedException`)
- Faz *retry* com *backoff* simples em erros transitórios
- Em caso de falha após várias tentativas, grava os eventos em arquivo local (`CW_FALLBACK_PATH`)

Na aplicação, você só precisa configurar o `.env` e usar `AppLog` — todo o detalhe fica encapsulado dentro do pacote.

---

## Estendendo para Outros Provedores

A arquitetura é agnóstica de transporte. Para suportar outro backend (Datadog, Loki, file-based, etc.):

1. Crie uma nova classe de *handler* em:

   ```text
   src/Logging/Transports/MeuProvider/MeuProviderHandler.php
   ```

   Ela deve implementar um *handler* do Monolog (`extends AbstractProcessingHandler` ou `HandlerInterface`).

2. Atualize o `HandlerFactory::make()` para adicionar o novo *case*:

   ```php
   return match ($provider) {
       'cloudwatch' => self::cloudwatch(...),
       'meuprovider' => self::meuProvider(...),
       default       => throw new RuntimeException(...),
   };
   ```

3. Adicione a configuração do provider em `config/app-logger.php` sob `'providers'`.

4. No `.env`, defina:

   ```dotenv
   APP_LOG_PROVIDER=meuprovider
   ```

Seu código de aplicação continua chamando `AppLog::info(...)`, sem necessidade de mudanças.

---

## Testes

O pacote foi pensado para ser fácil de testar:

- Você pode fazer *bind* de um `CloudWatchLogsClient` *mockado* no container.
- O `HandlerFactory` sempre prefere o client vindo do container quando disponível.
- Use `orchestra/testbench` para rodar testes de integração com um ambiente Laravel completo.

Fluxos de teste recomendados:

- Testes unitários para `Log4jLikeFormatter` (estrutura JSON).
- Testes unitários para `CloudwatchHandler` (batching, sequence token, fallback).
- Testes de *feature* para a facade `AppLog` e o canal `app_log` (wiring correto).

---

## Requisitos

- PHP 8.2+
- Laravel 10, 11 ou superior
- Credenciais AWS com permissões para:
  - `logs:CreateLogGroup`
  - `logs:CreateLogStream`
  - `logs:PutLogEvents`
  - `logs:DescribeLogStreams`

---

## Licença

Licenciado sob a licença MIT.

© EgypTeam / Pedro Ferreira
