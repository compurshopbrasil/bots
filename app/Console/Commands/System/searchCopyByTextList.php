<?php

namespace App\Console\Commands\System;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Helper\ProgressBar;

class SearchCopyByTextList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:search_copy {--origin=} {--dest=} {--list=} {--threads=auto} {--list_only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path_origin = Storage::disk("source")->path($this->option('origin') ?? $this->ask("Por favor, selecione a pasta de origem", getcwd() . "/docs"));
        $path_dest = Storage::disk("source")->path($this->option('dest') ?? $this->ask("Por favor, selecione a pasta de destino", getcwd() . "/dest"));
        $file_list = Storage::disk("source")->path($this->option('list') ?? $this->ask("Por favor, selecione a lista de arquivos", getcwd() . "/list.txt"));
        $list_only = $this->option('list_only') ?? $this->confirm("Deseja apenas listar os arquivos?", false);
        $threads = $this->option('threads') ?? $this->ask("Por favor, selecione a quantidade de threads", 'auto');

        if ($path_origin === null) {
            $this->error("Caminho de origem não existe");
            return Command::FAILURE;
        }

        if (!is_readable($path_origin)) {
            $this->error("Pasta de origem não possui permissão de leitura");
            return Command::FAILURE;
        }

        $path_origin = rtrim($path_origin, "/") . "/";

        $this->line("Listando pastas de origem e armazenando em memória para consulta...");
        $this->line("Pasta de origem: $path_origin");

        $directories = Storage::disk("source")->directories($path_origin);
        $directories = array_map(function ($item) {
            $item = basename($item);
            $item = ltrim($item);
            $item = str_replace([".", "-", "/"], "", $item);
            return str_pad($item, 11, "0", STR_PAD_LEFT);
        }, $directories);

        if (count($directories) === 0) {
            $this->error("Nenhuma pasta encontrada");
            return Command::FAILURE;
        }

        if (!file_exists($file_list) && !$list_only) {
            $this->error("Arquivo de lista não encontrado");
            return Command::FAILURE;
        }

        // List directories to txt file
        if ($list_only) {
            if (!is_writable(dirname($file_list))) {
                $this->error("Arquivo de lista não possui permissão de escrita");
                return Command::FAILURE;
            }

            $list = fopen($file_list, "w");

            foreach ($directories as $directory) {
                fwrite($list, $directory . "\n");
            }

            $this->info("Lista salva em $file_list");

            fclose($list);
            return Command::SUCCESS;
        }

        if (!file_exists($path_dest)) {
            if ($this->confirm("Pasta de destino não existe {$path_dest}, deseja criar?", true)) {
                if (!is_writable(dirname($path_dest))) {
                    $this->error("Pasta de destino não possui permissão de escrita");
                    return Command::FAILURE;
                }

                mkdir($path_dest, 0777, true);
            } else {
                $this->error("Pasta de destino não existe");
                return Command::FAILURE;
            }
        }

        if (!is_writable($path_dest)) {
            $this->error("Pasta de destino não possui permissão de escrita");
            return Command::FAILURE;
        }

        $path_dest = rtrim($path_dest, "/") . "/";

        $this->line("Pasta de destino: $path_dest");

        if (!is_readable($file_list)) {
            $this->error("Arquivo de lista não possui permissão de leitura");
            return Command::FAILURE;
        }

        $search_list = [];

        $list = fopen($file_list, "r");

        $this->line("Lendo lista de arquivos...");
        while (!feof($list)) {
            $line = fgets($list);
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $search_list[] = $line;
        }

        fclose($list);

        if (count($search_list) === 0) {
            $this->error("Nenhum arquivo encontrado na lista");
            return Command::FAILURE;
        }

        $this->line("CPFs encontrados: " . count($search_list));
        if ($threads === 'auto'){
            $this->line("Iniciando copia dos arquivos com {$threads} threads...");
        } else {
            $this->line("Iniciando copia dos arquivos, a quantidade de threads está em automatico");
            $this->line("O limitador de velocidade é a velocidade do seu HDD/SSD");
        }
        $this->newLine();

        $progress = new ProgressBar($this->output, count($search_list));
        $progress->setFormat("%message%\n\n[%bar%] %current%/%max% (%percent:3s%%) [%elapsed:6s%/%estimated:-6s%]\n");
        $progress->setOverwrite(true);
        $progress->setMessage('');
        $progress->start();

        foreach ($search_list as $cpf) {
            $progress->advance();

            $cpf = trim($cpf);

            if (empty($cpf)) {
                continue;
            }

            $cpf = str_replace([".", "-", "/"], "", $cpf);
            $cpf = str_pad($cpf, 11, "0", STR_PAD_LEFT);
            
            if (!in_array($cpf, $directories)) {
                $progress->setMessage("\n> CPF {$cpf} não encontrado");
                continue;
            }

            $progress->setMessage("\n> Copiando pasta {$cpf}\nOrigem: {$path_origin}{$cpf}\nDestino: {$path_dest}/{$cpf}");

            // verifica se a pasta ja existe no destino
            if (is_dir($path_dest . "/" . $cpf)) {
                $progress->setMessage("\n> Pasta {$cpf} já existe no destino, pulando...");
                continue;
            }
            
            if(File::copyDirectory($path_origin . "/" . $cpf, $path_dest . "/" . $cpf)) {
                $progress->setMessage("\n> Pasta {$cpf} copiada com sucesso");
            } else {
                $progress->setMessage("\n> Erro ao copiar pasta {$cpf}");
            }
        }

        $progress->finish();

        $this->info("\n> Concluído");

        return Command::SUCCESS;
    }
}
