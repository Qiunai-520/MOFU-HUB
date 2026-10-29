<?php

declare(strict_types=1);

namespace Mofu\Modules\Membership;

/**
 * 邮件发送：默认走 SMTP，未配置时回退到日志（本地开发可读 storage/logs/mail.log 取验证码）。
 */
final class MailService
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $logPath,
    ) {
    }

    public function enabled(): bool
    {
        return ($this->config['driver'] ?? 'log') === 'smtp' && (string) ($this->config['host'] ?? '') !== '';
    }

    /** 返回是否发送成功；失败时写入日志，不抛出（注册流程据此提示重试） */
    public function send(string $to, string $subject, string $body): bool
    {
        if (!$this->enabled()) {
            return $this->log($to, $subject, $body);
        }

        try {
            $this->smtp($to, $subject, $body);

            return true;
        } catch (\Throwable $e) {
            $this->append('SMTP 发送失败：' . $e->getMessage() . "\n" . $this->format($to, $subject, $body));

            return false;
        }
    }

    private function log(string $to, string $subject, string $body): bool
    {
        return $this->append($this->format($to, $subject, $body));
    }

    private function format(string $to, string $subject, string $body): string
    {
        return sprintf("[%s] to=%s subject=%s\n%s\n", date('Y-m-d H:i:s'), $to, $subject, $body);
    }

    private function append(string $text): bool
    {
        $dir = dirname($this->logPath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return @file_put_contents($this->logPath, $text, FILE_APPEND) !== false;
    }

    private function smtp(string $to, string $subject, string $body): void
    {
        $host = (string) $this->config['host'];
        $port = (int) ($this->config['port'] ?? 465);
        $encryption = (string) ($this->config['encryption'] ?? 'ssl');
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $from = (string) ($this->config['from'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));

        $remote = $encryption === 'ssl' ? 'ssl://' . $host : 'tcp://' . $host;
        $socket = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 12);

        if ($socket === false) {
            throw new \RuntimeException(sprintf('连接 %s 失败：%s', $remote, $errstr));
        }

        stream_set_timeout($socket, 12);

        $this->expect($socket, '220');
        $this->command($socket, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '250');

        if ($encryption === 'tls') {
            $this->command($socket, 'STARTTLS', '220');

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS 协商失败');
            }

            $this->command($socket, 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '250');
        }

        if ($username !== '') {
            $this->command($socket, 'AUTH LOGIN', '334');
            $this->command($socket, base64_encode($username), '334');
            $this->command($socket, base64_encode($password), '235');
        }

        $this->command($socket, 'MAIL FROM:<' . $from . '>', '250');
        $this->command($socket, 'RCPT TO:<' . $to . '>', '250');
        $this->command($socket, 'DATA', '354');

        $headers = [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date(DATE_RFC2822),
        ];

        $data = implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n")
            . "\r\n.";

        $this->command($socket, $data, '250');
        $this->command($socket, 'QUIT', '221');
        fclose($socket);
    }

    /** @param resource $socket */
    private function command($socket, string $command, string $expect): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $expect);
    }

    /** @param resource $socket */
    private function expect($socket, string $code): void
    {
        $response = '';

        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;

            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if (!str_starts_with($response, $code)) {
            throw new \RuntimeException('SMTP 响应异常：' . trim($response));
        }
    }
}
