<?php
/**
 * Bootstrap do backend das doações (`/doe/api/`).
 *
 * Reaproveita o backend do checkout de cursos (mesmo banco, mesmo cliente da Unicopag, mesma
 * moldura de e-mail): este arquivo só acrescenta o que é próprio da doação. A conta da Unicopag
 * é outra (UNICO_API_KEY_DOACAO em /doe/api/config.php) e a tabela é a mcp_doacoes.
 *
 * Endpoints públicos: info.php, doacoes.php, status.php, webhook.php.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/matricula-cursos-presenciais/api/lib.php';
require __DIR__ . '/lib/doacao.php';
require __DIR__ . '/lib/email_doacao.php';
