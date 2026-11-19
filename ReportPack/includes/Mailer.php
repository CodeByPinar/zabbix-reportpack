<?php

namespace Modules\ReportPack\Includes;

use RuntimeException;

/**
 * Sends e-mail with attachments over SMTP: plain, STARTTLS or SSL/TLS, with optional login.
 * Needs no PHP extension beyond OpenSSL for encrypted connections.
 */
class Mailer {

	private array $config;

	/** @var resource|null */
	private $socket = null;

	private array $log = [];

	/**
	 * @param array $config  host, port, security (none|starttls|ssl), username, password, from, from_name,
	 *                       verify_tls (bool), helo (optional host name for EHLO).
	 */
	public function __construct(array $config) {
		$this->config = $config + [
			'port' => 25,
			'security' => 'none',
			'username' => '',
			'password' => '',
			'from_name' => '',
			'verify_tls' => true,
			'helo' => ''
		];

		if (($this->config['host'] ?? '') === '' || ($this->config['from'] ?? '') === '') {
			throw new RuntimeException('SMTP host and sender address must be set.');
		}
	}

	/**
	 * @param string[] $to
	 * @param array    $attachments  [['name' => file name, 'type' => MIME type, 'data' => bytes], ...]
	 */
	public function send(array $to, string $subject, string $text, array $attachments = []): void {
		$message = self::message($this->config['from'], $this->config['from_name'], $to, $subject, $text,
			$attachments
		);

		try {
			$this->connect();
			$this->command('MAIL FROM:<'.$this->config['from'].'>', [250]);

			foreach ($to as $address) {
				$this->command('RCPT TO:<'.$address.'>', [250, 251]);
			}

			$this->command('DATA', [354]);

			// Lines starting with a dot are doubled so the server does not read them as the end of the message.
			$body = preg_replace('/^\./m', '..', $message);
			$this->command($body."\r\n.", [250]);
			$this->command('QUIT', [221], false);
		}
		finally {
			$this->close();
		}
	}

	/**
	 * Conversation with the server, without passwords, for --verbose.
	 */
	public function log(): array {
		return $this->log;
	}

	/**
	 * The complete MIME message: a UTF-8 text part and base64 attachments.
	 */
	public static function message(string $from, string $from_name, array $to, string $subject, string $text,
			array $attachments, ?int $time = null): string {
		$boundary = '=_ReportPack_'.bin2hex(random_bytes(8));
		$domain = substr(strrchr($from, '@') ?: '@localhost', 1);

		$headers = [
			'Date: '.date('r', $time ?? time()),
			'From: '.($from_name !== '' ? self::encodeHeader($from_name).' <'.$from.'>' : $from),
			'To: '.implode(', ', $to),
			'Subject: '.self::encodeHeader($subject),
			'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$domain.'>',
			'MIME-Version: 1.0',
			'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
			'X-Mailer: Report Pack for Zabbix'
		];

		$parts = "--".$boundary."\r\n"
			."Content-Type: text/plain; charset=UTF-8\r\n"
			."Content-Transfer-Encoding: base64\r\n\r\n"
			.rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text))), 76, "\r\n"))
			."\r\n";

		foreach ($attachments as $attachment) {
			$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachment['name']);
			$parts .= "--".$boundary."\r\n"
				.'Content-Type: '.$attachment['type'].'; name="'.$name."\"\r\n"
				."Content-Transfer-Encoding: base64\r\n"
				.'Content-Disposition: attachment; filename="'.$name."\"\r\n\r\n"
				.rtrim(chunk_split(base64_encode($attachment['data']), 76, "\r\n"))
				."\r\n";
		}

		$parts .= "--".$boundary."--";

		return implode("\r\n", $headers)."\r\n\r\n".$parts;
	}

	/**
	 * RFC 2047 encoding for header text that is not plain ASCII.
	 */
	public static function encodeHeader(string $text): string {
		$text = preg_replace('/[\r\n]+/', ' ', $text);

		if (!preg_match('/[^\x20-\x7E]/', $text)) {
			return $text;
		}

		// Encoded words are limited to 75 characters, so long text is split at character boundaries.
		$words = [];

		foreach (mb_str_split($text, 12, 'UTF-8') as $chunk) {
			$words[] = '=?UTF-8?B?'.base64_encode($chunk).'?=';
		}

		return implode("\r\n ", $words);
	}

	private function connect(): void {
		$security = $this->config['security'];
		$address = ($security === 'ssl' ? 'ssl://' : 'tcp://').$this->config['host'].':'.(int) $this->config['port'];

		$context = stream_context_create(['ssl' => [
			'verify_peer' => (bool) $this->config['verify_tls'],
			'verify_peer_name' => (bool) $this->config['verify_tls'],
			'peer_name' => $this->config['host']
		]]);

		$socket = @stream_socket_client($address, $errno, $error, 30, STREAM_CLIENT_CONNECT, $context);

		if ($socket === false) {
			throw new RuntimeException('Cannot connect to the mail server '.$address.': '.$error);
		}

		stream_set_timeout($socket, 60);
		$this->socket = $socket;

		$this->expect([220]);
		$helo = $this->config['helo'] !== '' ? $this->config['helo'] : (gethostname() ?: 'localhost');
		$features = $this->command('EHLO '.$helo, [250]);

		if ($security === 'starttls') {
			$this->command('STARTTLS', [220]);

			if (!@stream_socket_enable_crypto($this->socket, true,
					STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
				throw new RuntimeException('STARTTLS failed with '.$this->config['host'].'.');
			}

			$features = $this->command('EHLO '.$helo, [250]);
		}

		if ($this->config['username'] !== '') {
			if (stripos($features, 'AUTH') === false) {
				throw new RuntimeException('The mail server does not offer login on this connection; use starttls or ssl.');
			}

			$this->command('AUTH LOGIN', [334]);
			$this->command(base64_encode($this->config['username']), [334]);
			$this->command(base64_encode($this->config['password']), [235], true, true);
		}
	}

	private function command(string $line, array $codes, bool $check = true, bool $secret = false): string {
		$this->log[] = 'C: '.($secret ? '********' : (strlen($line) > 200 ? substr($line, 0, 60).'… ('.strlen($line).' bytes)' : $line));

		if (@fwrite($this->socket, $line."\r\n") === false) {
			throw new RuntimeException('Lost connection to the mail server.');
		}

		return $check ? $this->expect($codes) : '';
	}

	private function expect(array $codes): string {
		$response = '';

		while (($line = fgets($this->socket, 1024)) !== false) {
			$response .= $line;

			// The last line of a reply has a space after the code.
			if (strlen($line) < 4 || $line[3] === ' ') {
				break;
			}
		}

		$this->log[] = 'S: '.trim($response);
		$code = (int) substr($response, 0, 3);

		if (!in_array($code, $codes, true)) {
			throw new RuntimeException('Mail server answered: '.trim($response));
		}

		return $response;
	}

	private function close(): void {
		if ($this->socket !== null) {
			@fclose($this->socket);
			$this->socket = null;
		}
	}
}
