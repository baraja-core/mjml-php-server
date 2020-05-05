<?php

declare(strict_types=1);

namespace Baraja\Mjml;


final class MjmlServer
{

	/** @var string */
	private $cacheDir;


	public function __construct(?string $cacheDir = null)
	{
		$this->cacheDir = rtrim($cacheDir ?? __DIR__ . '/../cache', '/');
		$this->createDir($this->cacheDir);
	}


	public function run(): void
	{
		if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] === '/api/v1/mjml') {
			header('Content-Type: application/json');
			try {
				echo json_encode([
					'status' => 'ok',
					'content' => (new self)->process($_POST['template'] ?? ''),
				]);
			} catch (\Throwable $e) {
				echo json_encode([
					'status' => 'error',
					'message' => $e->getMessage(),
				]);
			}
			die;
		}
	}


	/**
	 * @param string $template
	 * @return string
	 */
	public function process(string $template): string
	{
		$templateContentHash = md5($template);
		$sourceFile = $this->cacheDir . '/' . $templateContentHash . '.mjml';
		$finalFile = $this->cacheDir . '/' . $templateContentHash . '.html';

		if (\is_file($finalFile)) {
			return $this->processReturn($finalFile);
		}

		$this->write($sourceFile, $template);

		if ($this->functionIsAvailable('shell_exec') === false) {
			throw new \RuntimeException('Function shell_exec() is disabled on this server.');
		}

		shell_exec('/node_modules/.bin/mjml ' . escapeshellarg($sourceFile) . ' > ' . escapeshellarg($finalFile));

		return $this->processReturn($finalFile);
	}


	/**
	 * @param string $file
	 * @return string
	 */
	private function processReturn(string $file): string
	{
		$return = str_replace(["\r\n", "\r"], "\n", $this->read($file));

		if (preg_match('/Error: ([^\n]+)/', $return, $parser)) {
			throw new \RuntimeException($parser[1]);
		}

		return $return;
	}


	/**
	 * @param string $dir
	 * @param int $mode
	 */
	private function createDir(string $dir, int $mode = 0777): void
	{
		if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) { // @ - dir may already exist
			throw new \RuntimeException('Unable to create directory "' . $dir . '". ' . $this->getLastError());
		}
	}


	/**
	 * @param string $file
	 * @return string
	 */
	private function read(string $file): string
	{
		$content = @file_get_contents($file); // @ is escalated to exception
		if ($content === false) {
			throw new \RuntimeException("Unable to read file '$file'. " . $this->getLastError());
		}

		return $content;
	}


	/**
	 * @param string $file
	 * @param string $content
	 * @param int|null $mode
	 */
	private function write(string $file, string $content, ?int $mode = 0666): void
	{
		$this->createDir(dirname($file));
		if (@file_put_contents($file, $content) === false) { // @ is escalated to exception
			throw new \RuntimeException("Unable to write file '$file'. " . $this->getLastError());
		}
		if ($mode !== null && !@chmod($file, $mode)) { // @ is escalated to exception
			throw new \RuntimeException("Unable to chmod file '$file'. " . $this->getLastError());
		}
	}


	/**
	 * @return string
	 */
	private function getLastError(): string
	{
		return preg_replace('#^\w+\(.*?\): #', '', error_get_last()['message']);
	}


	/**
	 * @param string $functionName
	 * @return bool
	 */
	private function functionIsAvailable(string $functionName): bool
	{
		static $disabled;

		if (\function_exists($functionName)) {
			if ($disabled === null && \is_string($disableFunctions = ini_get('disable_functions'))) {
				$disabled = explode(',', $disableFunctions) ?: [];
			}

			return \in_array($functionName, $disabled, true) === false;
		}

		return false;
	}
}