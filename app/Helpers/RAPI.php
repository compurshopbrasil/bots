<?php

namespace App\Helpers;

class RAPI
{
    private bool $success = false;
    private array $messages = [];
    private mixed $data = null;

    public static function __callStatic(string $name, array $arguments): mixed
    {
        $response = new self();

        if (method_exists($response, $name)) {
            return call_user_func_array([$response, $name], $arguments);
        }

        return null;
    }

    public function addMessage(string $type = 'info', string $message): void
    {
        $this->messages[] = (object) [
            'type' => $type, // 'info', 'error', 'warning', 'success'
            'msg' => $message
        ];
    }

    public function addMessages(array $messages): void
    {
        foreach ($messages as $type => $message) {
            $this->addMessage($type, $message);
        }
    }

    public function setData(mixed $data): void
    {
        $this->data = $data;
    }

    public function success(): void
    {
        $this->success = true;
    }

    public function fail(): void
    {
        $this->success = false;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'messages' => $this->messages,
            'data' => $this->data
        ];
    }

    public function data(): object
    {
        return (object) $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    public static function create(): self
    {
        return new self();
    }
}
