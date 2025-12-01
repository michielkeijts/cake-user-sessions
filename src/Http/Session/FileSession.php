<?php
/**
 * FilesSessionHandler to be able to use files as save option for sessions
 * as well. The default php-files handler cannot be found
 */

namespace UserSessions\Http\Session;

use SessionHandlerInterface;

class FileSession implements SessionHandlerInterface
{
    private $savePath;

    public function __construct(array $options)
    {
        $this->savePath = $options['savePath'];
    }

    function open($savePath, $sessionName): bool
    {
        $this->savePath = $savePath;
        if (!is_dir($this->savePath)) {
            mkdir($this->savePath, 0777);
        }

        return true;
    }

    function close(): bool
    {
        return true;
    }

    function read($id): string|false
    {
        return (string)@file_get_contents("$this->savePath/sess_$id");
    }

    function write($id, $data): bool
    {
        return file_put_contents("$this->savePath/sess_$id", $data) === false ? false : true;
    }

    function destroy($id): bool
    {
        $file = "$this->savePath/sess_$id";
        if (file_exists($file)) {
            unlink($file);
        }

        return true;
    }

    function gc($maxlifetime): int|false
    {
        foreach (glob("$this->savePath/sess_*") as $file) {
            if (filemtime($file) + $maxlifetime < time() && file_exists($file)) {
                unlink($file);
                return 1;
            }
        }

        return false;
    }
}
