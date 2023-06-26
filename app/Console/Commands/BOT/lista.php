<?php

namespace App\Console\Commands\BOT;

use App\Helpers\Utils as HelperUtils;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;

class Lista extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:lista {--file=} {--model=} {--output=} {--overwrite} {--threads=64}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    // Messages
    private array $messages = [];

    // Api Params
    private static array $API_PARAMS;

    // File output handler
    private mixed $file_out;

    // File input handler
    private mixed $file_in;

    // Prefix of messages
    private string $prefix;

    // Progress Bar
    private ProgressBar $progress;

    private string $filename_output;
    private string $filename_input;

    private bool $use_cache = false;

    /**
     * Proccess CPF
     */
    private function doProccess(string $cpf, object $data): bool
    {
        $_data = [
            'CPF' => $cpf,
        ];

        foreach (self::$API_PARAMS as $param) {
            if ($param === 'CPF') {
                continue;
            }

            if (!isset($data->$param)) {
                $_data[$param] = '';
                continue;
            }

            if (is_null($data->$param)) {
                $_data[$param] = '';
                continue;
            }

            if (is_array($data->$param)) {
                $_data[$param] = implode(',', $data->$param);
                continue;
            }

            if (is_object($data->$param)) {
                $_data[$param] = json_encode($data->$param);
                continue;
            }

            if (is_bool($data->$param)) {
                $_data[$param] = $data->$param ? 'true' : 'false';
                continue;
            }

            if ($param === 'D2_DIB') {
                $data->$param = str_pad($data->$param, 8, '0', STR_PAD_LEFT);
                $_data[$param] = substr($data->$param, 0, 2) . '/' . substr($data->$param, 2, 2) . '/' . substr($data->$param, 4, 4);
                continue;
            }

            if ($param === 'D2_DDB') {
                $data->$param = str_pad($data->$param, 8, '0', STR_PAD_LEFT);
                $_data[$param] = substr($data->$param, 0, 2) . '/' . substr($data->$param, 2, 2) . '/' . substr($data->$param, 4, 4);
                continue;
            }

            if ($param === 'DT_NASCIMENTO_T') {
                $data->$param = str_pad($data->$param, 8, '0', STR_PAD_LEFT);
                $_data[$param] = substr($data->$param, 0, 2) . '/' . substr($data->$param, 2, 2) . '/' . substr($data->$param, 4, 4);
                continue;
            }

            $_data[$param] = $data->$param;
        }

        // Write CSV Line
        if (fputcsv($this->file_out, $_data, ';') === false) {
            $this->error("$this->prefix Não foi possível escrever no arquivo de saída");
            return false;
        }

        return true;
    }

    /**
     * Messages
     */
    private function message(int $send_at = 8): bool
    {
        if (count($this->messages) >= $send_at) {
            $this->messages = array_unique($this->messages);
            $this->messages = array_values($this->messages);
            $this->messages = array_filter($this->messages);
            $this->messages = array_map(function ($msg) {
                return trim($msg);
            }, $this->messages);
            $this->messages = array_reverse($this->messages);
            $this->messages = array_slice($this->messages, 0, $send_at);

            $this->progress->setMessage(implode(PHP_EOL, $this->messages));

            // Clear messages
            $this->messages = [];
            return true;
        }

        return false;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $API_URL = env("API_URL");
        if (empty($API_URL)) {
            $this->error("API URL não configurada, use o comando \"php artisan key:generate\" para gerar o .env\nDepois use o comando \"php artisan config:cache\" para atualizar as configurações");
            return Command::FAILURE;
        }

        $this->filename_input = $this->option('file') ?? $this->ask('Por favor, selecione o arquivo a ser processado');
        $this->filename_output = str_replace('.csv', '_processado.csv', $this->filename_input);

        match ($this->option('model')) {
            '1' => self::$API_PARAMS = [
                "CPF",
                "NU_NB",
                "NM_INSTITUIDOR_I",
                "NM_MAE_I",
                "ID_NIT_I",
                "DT_NASCIMENTO_I",
            ],
            '2' => self::$API_PARAMS = [
                "CPF",
                "NU_NB",
                "VL_MR_ATU",
                "VL_RMI",
                "CS_ESPECIE",
                "D2_DIB",
                "D2_DDB",
                "ID_BANCO",
                "ID_ORGAO_PAGADOR",
                "CS_MEIO_PAGTO",
                "NU_AGENCIA_PAG",
                "NU_CONTA_CORRENTE",
                "NM_TITULAR_BENEF_T",
                "NM_MAE_T",
                "NU_CPF_T",
                "DT_NASCIMENTO_T",
                "CS_SEXO_T",
                "TE_ENDERECO_T",
                "NM_BAIRRO_T",
                "NU_CEP_T",
                "NM_MUNICIPIO_T",
                "NM_UF_MUNICIPIO_T"
            ],
            default => self::$API_PARAMS = [
                "CPF",
                "NU_NB",
                "VL_MR_ATU",
                "VL_RMI",
                "CS_ESPECIE",
                "D2_DIB",
                "D2_DDB",
                "ID_BANCO",
                "ID_ORGAO_PAGADOR",
                "CS_MEIO_PAGTO",
                "NU_AGENCIA_PAG",
                "NU_CONTA_CORRENTE",
                "NM_TITULAR_BENEF_T",
                "NM_MAE_T",
                "NU_CPF_T",
                "DT_NASCIMENTO_T",
                "CS_SEXO_T",
                "TE_ENDERECO_T",
                "NM_BAIRRO_T",
                "NU_CEP_T",
                "NM_MUNICIPIO_T",
                "NM_UF_MUNICIPIO_T"
            ],
        };

        if ($this->filename_input === null) {
            $this->error('Nenhum arquivo selecionado');
            return Command::FAILURE;
        }

        $storage_in = Storage::disk('in');
        $path_in = $storage_in->path($this->filename_input);

        if (!$storage_in->exists($this->filename_input)) {
            $this->error('Não foi possível encontrar o arquivo ' . $this->filename_input);
            return Command::FAILURE;
        }

        $storage_out = Storage::disk('out');
        $path_out = $storage_out->path($this->filename_output);

        if ($storage_out->exists($this->filename_output)) {
            if (!$this->confirm('Deseja sobrescrever o arquivo de saída?')) {
                return Command::FAILURE;
            }
        }

        $threads = $this->option('threads') ?? $this->anticipate('Por favor, selecione a quantidade de threads', [1, 4, 8, 16, 32, 64, 96, 128]);
        if ($threads === null) {
            $this->error('Nenhuma quantidade de threads selecionada');
            return Command::FAILURE;
        }

        $threads = (int) $threads;
        if ($threads <= 0) {
            $this->error('A quantidade de threads não pode ser menor ou igual a 0');
            return Command::FAILURE;
        }

        if ($threads > 128) {
            $this->error('A quantidade de threads não pode ser maior que 128');
            return Command::FAILURE;
        }

        $this->info("> Estamos processando o arquivo {$this->filename_input} com $threads threads");
        $this->info("> Caminho de entrada: {$path_in}");
        $this->info("> Caminho de saída: {$path_out}\n");

        // Prepare Guzzle Client
        $client = new Client([
            'base_uri' => $API_URL,
            'timeout' => 20.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
            ],
        ]);

        // Prepare CSV File
        $this->file_in = fopen($path_in, 'r');
        $this->file_out = fopen($path_out, 'w');

        if ($this->file_in === false) {
            $this->error('Não foi possível abrir o arquivo de entrada');
            return Command::FAILURE;
        }

        if ($this->file_out === false) {
            $this->error('Não foi possível criar o arquivo de saída');
            return Command::FAILURE;
        }

        if (filesize($path_in) === 0) {
            $this->error('O arquivo de entrada está vazio');
            return Command::FAILURE;
        }

        if (filesize($path_out) > 0 && !$this->option('overwrite')) {
            if (!$this->confirm('Deseja sobrescrever o arquivo de saída?')) {
                return Command::FAILURE;
            }
        }

        $this->info('Testando conexão com a API...');
        // Test API Connection
        try {
            $response = $client->get('/select/select.php?cpf=30193095068');
            if ($response->getStatusCode() !== 200) {
                $this->error('Não foi possível se conectar a API');
                return Command::FAILURE;
            }
        } catch (Exception $e) {
            $this->error('Não foi possível se conectar a API');
            return Command::FAILURE;
        }

        $this->info('Conexão com a API estabelecida com sucesso, iniciando processamento...');

        // Write CSV Headers
        fputcsv($this->file_out, self::$API_PARAMS, ';');

        $lines = 0;
        $promises = [];
        $promises_cpf = [];
        $total_lines = count(file($path_in));
        $max_lines = -1; //test only

        $this->progress = $this->output->createProgressBar($total_lines);
        $this->progress->setFormat("%message%\n\n[%bar%] [%current%/%max%, CPFs Processados] (%percent:3s%%) [%elapsed:6s%/%estimated:-6s%] %memory:6s%\n");
        $this->progress->setOverwrite(true);
        $this->progress->setMessage('');
        $this->progress->setProgressCharacter("\xF0\x9F\x8D\xBA");
        $this->progress->setBarCharacter("\xF0\x9F\x8D\xBA");
        $this->progress->setEmptyBarCharacter("\xE2\x9D\x8C");
        $this->progress->start();

        // Read CSV File
        while (($line = fgetcsv($this->file_in)) !== false) {
            $lines++;
            $this->progress->advance();
            $this->message();

            if ($max_lines !== -1 && $lines > $max_lines) {
                break;
            }

            $row = str_getcsv($line[0], ';');
            $cpf = $row[0];

            if (!HelperUtils::isValidCPF($cpf)) {
                $this->messages[] = "[Linha: {$lines}, CPF: {$cpf}]: CPF inválido, pulando...";
                continue;
            }

            $cache_tag = 'bot_list.' . sha1($cpf);
            $this->prefix = "[Linha: {$lines}, CPF: {$cpf}]:";

            if ($this->use_cache) {
                if (Cache::has($cache_tag)) {
                    $data = Cache::get($cache_tag);

                    if ($data !== false || $data !== null) {
                        $this->messages[] = "$this->prefix Dados em cache, processando...";
                        if (!$this->doProccess($cpf, $data)) {
                            return Command::FAILURE;
                        }

                        continue;
                    } else {
                        if (Cache::forget($cache_tag)) {
                            $this->messages[] = "$this->prefix Dados em cache inválidos ou corrompidos, cache removido, tentanto via API...";
                        } else {
                            $this->messages[] = "$this->prefix Dados em cache inválidos ou corrompidos, não foi possível remover do cache, tentanto via API...";
                        }
                    }
                }
            }

            $promises[] = $client->requestAsync('GET', '/select/select.php', [
                'query' => [
                    'cpf' => $cpf,
                ],
            ]);

            $promises_cpf[] = $cpf;
            if ($lines % $threads === 0 || $lines === $total_lines || $lines === $max_lines) {
                try {
                    $responses = Utils::unwrap($promises);
                } catch (\Exception $e) {
                    $this->error($e->getMessage());
                    break;
                }

                $response_row = 0;
                foreach ($responses as $response) {
                    $cpf = $promises_cpf[$response_row];

                    $tmp_lines = ($lines - $threads) + $response_row;
                    $this->prefix = "[Linha: {$tmp_lines}, CPF: {$cpf}]:";

                    if ($response->getStatusCode() !== 200) {
                        $this->messages[] = "$this->prefix Não pode ser processado, o servidor retornou um erro, (CODE: {$response->getStatusCode()}), pulando...";
                        $response_row++;
                        continue;
                    }

                    $body = $response->getBody()->getContents();
                    $data = json_decode($body);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->messages[] = "$this->prefix Não pode ser processado, a resposta do servidor não é um JSON válido, pulando...";
                        $response_row++;
                        continue;
                    }

                    if ($data === false) {
                        $this->messages[] = "$this->prefix Não pode ser processado, a api não retornou dados, pulando...";
                        $response_row++;
                        continue;
                    }

                    if (!is_object($data)) {
                        $this->messages[] = "$this->prefix Não pode ser processado, a resposta do servidor não é um objeto, pulando...";
                        $response_row++;
                        continue;
                    }

                    if (!$this->doProccess($cpf, $data)) {
                        return Command::FAILURE;
                    }

                    if ($this->use_cache) {
                        if (Cache::forever($cache_tag, $data)) {
                            $this->messages[] = "$this->prefix Processado e salvo no cache...";
                        } else {
                            $this->messages[] = "$this->prefix Processado mas não foi possível salvar no cache...";
                        }
                    } else {
                        $this->messages[] = "$this->prefix Processado...";
                    }


                    $response_row++;
                }

                $promises = [];
                $promises_cpf = [];
            }
        }

        $this->progress->finish();

        fclose($this->file_in);
        fclose($this->file_out);

        $this->info("\n> Arquivo de saída gerado com sucesso: {$this->filename_output}");

        return Command::SUCCESS;
    }
}
