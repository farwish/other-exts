<?php
/**
 * Via package.
 *
 * @license Apache-2.0
 * @author farwish <farwish@foxmail.com>
 */

namespace Via;

use Exception;

class Container
{
    /**
     * Worker number.
     *
     * @var int $count
     */
    protected $count = 1;

    /**
     * Internet address or Unix domain.
     *
     * @var string $localSocket
     */
    protected $localSocket = null;

    /**
     * Protocol of socket.
     *
     * @var string $protocol
     */
    protected $protocol = null;

    /**
     * Address of socket.
     *
     * @var string $address
     */
    protected $address = null;

    /**
     * Stream return by stream_socket_server.
     *
     * @var Resource $socketStream
     */
    protected $socketStream = null;

    /**
     * Socket accept timeout (seconds).
     *
     * @var float $timeout
     */
    protected $timeout = 60;

    /**
     * Port of socket.
     *
     * @var int $port
     */
    protected $port = null;

    /**
     * Worker process id container.
     *
     * Format likes: [72507 => 72507, 72508 => 72508]]
     *
     * @var array $pids
     */
    protected $pids = [];

    /**
     * Monitored signals.
     *
     * @var array $signals
     */
    protected $signals = [
        SIGINT  => 'SIGINT',  // 2   interrupted by keyboard (ctrl+c).
        SIGQUIT => 'SIGQUIT', // 3   quit by keyboard (ctrl+\).
        SIGUSR1 => 'SIGUSR1', // 10
        SIGUSR2 => 'SIGUSR2', // 12
        SIGTERM => 'SIGTERM', // 15  terminated by `kill 72507`, and SIGKILL and SIGSTOP can not be catch.
        SIGCHLD => 'SIGCHLD', // 17  normal child exit.
    ];

    /**
     * Usable command.
     *
     * @var array $commands
     */
    protected $commands = [
        'start',
        'restart',
        'stop',
    ];

    /**
     * Is in daemon.
     *
     * @var bool $daemon
     */
    protected $daemon = true;

    /**
     * Process title.
     *
     * @var string $title
     */
    protected $title = 'Via process';

    /**
     * Constructor.
     *
     * @param string $socket
     *
     * @return void
     */
    public function __construct(string $socket = '')
    {
        $this->localSocket = $socket ?: null;
    }

    /**
     * Set worker number.
     *
     * @param int $count
     *
     * @return $this
     * @throws Exception
     */
    public function setCount(int $count)
    {
        if ((int)$count > 0) {
            $this->count = $count;
        } else {
            throw new Exception('Error: Illegal worker process number.' . PHP_EOL);
        }

        return $this;
    }

    /**
     * Set socket.
     *
     * Use this function or initialize socket in Constructor.
     *
     * @param string $socket
     *
     * @return $this
     */
    public function setSocket(string $socket = '')
    {
        $this->localSocket = $socket ?: null;

        return $this;
    }

    /**
     * Set process title.
     *
     * @param string $title
     *
     * @return $this
     */
    public function setTitle(string $title)
    {
        if ($title) $this->title = $title;

        return $this;
    }

    /**
     * Start run.
     *
     * @return void
     */
    public function start()
    {
        self::command();

        self::createServer();

        self::installSignal();

        self::forks();

        self::monitor();
    }

    /**
     * Parse command and option.
     *
     * @return void
     */
    protected function command()
    {
        global $argv;

        $command = $argv[1] ?? null;
        $stash   = $argv;
        unset($stash[0], $stash[1]);

        // Parse option.
        if ($stash) {
            foreach ($stash as $option) {
                if (! strstr($option, '=')) goto Usage;
                list($k, $v) = explode('=', $option);
                switch ($k) {
                    case '--env':
                        if ($v === 'dev') {
                            $this->daemon = false;
                        } elseif (empty($v) || $v === 'prod') {
                            $this->daemon = true;
                        }
                        break;
                    default:
                        break;
                }
            }
        }

        // Parse command.
        if (in_array($command, $this->commands)) {
            switch ($command) {
                case 'start':
                    break;
                case 'restart':
                    break;
                case 'stop':
                    // need master pid somewhere.
                    break;
                default:
                    break;
            }
        } else {

            Usage:

            echo "Usage:\n    php $argv[0] {start|restart|stop} [Options]" . PHP_EOL;
            echo "Options:" . PHP_EOL;
            echo "    --env=dev       It runs in foreground, show debug message, helpful in developing." . PHP_EOL;
            echo "    --env=prod      This is default choice that runs in daemon, for production environment." . PHP_EOL;
            echo PHP_EOL;
            exit();
        }
    }

    /**
     * Install signal handler in master process.
     *
     * Parent catch the signal, child will extends the signal handler.
     * But it not meens child will receive the signal too, SIGTERM is
     * exception, if parent catch SIGTERM, child will not received, so this
     * signal should be reinstall in the child.
     *
     * If child process terminated, monitor will fork again.
     *
     * PCNTL signal constants:
     * @see http://php.net/manual/en/pcntl.constants.php
     *
     * @return void
     */
    protected function installSignal()
    {
        declare(ticks = 1);

        foreach ($this->signals as $signal => $name) {
            pcntl_signal($signal, function($signo, $siginfo) use ($name) {
                if (! $this->daemon) {
                    echo "Pid " . posix_getpid() . " received signal number {$signo} ({$name})" . PHP_EOL;
                }

                switch ($signo) {
                    case SIGINT:
                        // Exit script normally.
                        exit();
                        break;

                    case SIGQUIT:
                        // Exit script normally.
                        exit();
                        break;

                    case SIGUSR1:
                        break;

                    case SIGUSR2:
                        break;

                    case SIGTERM:
                        // If parent catch the signal, it will cause block.
                        // So child need reinstall the handler.
                        pcntl_signal(SIGTERM, SIG_DFL);
                        break;

                    case SIGCHLD:
                        pcntl_signal(SIGCHLD, SIG_DFL);
                        break;

                    default:
                        break;
                }
            });
        }
    }

    /**
     * Install signal handler in child process.
     *
     * @return void
     */
    protected function installChildSignal()
    {
        foreach ($this->signals as $signal => $name) {
            switch ($signal) {
                case SIGTERM:
                    // If parent catch the signal, it will cause block.
                    // So child need reinstall the handler.
                    pcntl_signal(SIGTERM, SIG_DFL);
                    break;

                case SIGCHLD:
                    pcntl_signal(SIGCHLD, SIG_DFL);
                    break;

                default:
                    break;
            }
        }
    }

    /**
     * Create socket server.
     *
     * Master create socket and listen, later on descriptor can be used in child.
     *
     * @return void
     * @throws Exception
     */
    protected function createServer()
    {
        if ($this->localSocket) {
            // Parse socket name, Unix domain ?
            $list = explode(':', $this->localSocket);
            $this->protocol = $list[0] ?? null;
            $this->address  = $list[1] ? ltrim($list[1], '\/\/') : null;
            $this->port     = $list[2] ?? null;

            // Create a stream context.
            // Options see php.net/manual/en/context.php
            $options = [
                'socket' => [
                    'bindto'        => $this->address . ':' . $this->port,
                    'backlog'       => 1,
                    'so_reuseport'  => true,
                ],
            ];
            $params  = null;
            $context = stream_context_create($options, $params);

            // Create an Internet or Unix domain server socket.
            $errno   = 0;
            $errstr  = '';
            $flags   = ($this->protocol === 'udp') ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $stream  = stream_socket_server($this->localSocket, $errno, $errstr, $flags, $context);
            if (! $stream) {
                throw new Exception("Create socket server fail, errno: {$errno}, errstr: {$errstr}" . PHP_EOL);
            }
            $this->socketStream = $stream;

            // Switch to non-blocking mode,
            // affacts calls like fgets and fread that read from the stream.
            if (! stream_set_blocking($stream, false)) {
                throw new Exception('Switch to non-blocking mode fail' . PHP_EOL);
            }
        }
    }

    /**
     * Fork workers until reach 'count' number.
     *
     * @return void
     */
    protected function forks()
    {
        while ( count($this->pids) < $this->count ) {
            $pid = pcntl_fork();

            switch($pid) {
                case -1:
                    throw new Exception("Fork failed." . PHP_EOL);
                    break;
                case 0:
                    // Child process, do business, can exit at last.
                    cli_set_process_title($this->title);

                    self::installChildSignal();

                    /*
                    sleep(1); $rand = rand(2, 20);
                    echo "New child process (pid=" . posix_getpid() . ") will spend {$rand} seconds doing work." . PHP_EOL;
                    sleep($rand);
                    sleep(30);
                     */

                    $read[] = $this->socketStream;
                    $write = null;
                    $except = [];
                    $sec = 60;

                    // Warning raised if the system call is interrupted by an incoming signal, timeout be zero and FALSE on error.
                    while (@stream_select($read, $write, $except, $sec)) {
                        if ( $connection = @stream_socket_accept($this->socketStream, 30) ) {
                            $str = fread($connection, 1024);
                            fwrite($connection, 'Server say:' . date('Y-m-d H:i:s') . ' ' . $str . PHP_EOL);
                            fclose($connection);
                        }
                    }

                    exit();
                    break;
                default:
                    // Parent(master) process, not do business, cant exit.
                    cli_set_process_title('Master process ' . $this->title);
                    $this->pids[$pid] = $pid;
                    break;
            }
        }
    }

    /**
     * Monitor any child process that terminated.
     *
     * Wait no hang.
     *
     * @return void
     */
    protected function monitor()
    {
        do {
            if ($terminated_pid = pcntl_waitpid(-1, $status, WNOHANG)) {

                if (! $this->daemon) {
                    self::debugSignal($terminated_pid, $status);
                }

                unset($this->pids[$terminated_pid]);

                // Fork again condition: normal exited or killed by SIGTERM.
                if ( pcntl_wifexited($status) ||
                    (pcntl_wifsignaled($status) && in_array(pcntl_wtermsig($status), [SIGTERM]) )
                ) {
                    self::forks();
                }
            }
        } while ( count($this->pids) > 0 );
    }

    /**
     * Output info when child quit.
     *
     * `kill -TERM 80382` as kill 80382
     * `kill -STOP 80382` stop a process to quit.
     * `kill -CONT 80382` continue a process stopped.
     *
     * @param int $status which reference changed by waitpid.
     *
     * @return void
     */
    protected function debugSignal($pid, $status)
    {
        $message = "Child process {$pid} terminated, ";

        if (pcntl_wifexited($status)) {
            $message .= "Normal exited with status " . pcntl_wexitstatus($status);
        }

        if (pcntl_wifsignaled($status)) {
            $message .= "Signal killed by signal number " . pcntl_wtermsig($status) . " (" . ($this->signals[ pcntl_wtermsig($status) ] ?? 'Unknow') . ")";
        }

        if (pcntl_wifstopped($status)) {
            $message .= "Signal stopped by signal number " . pcntl_wstopsig($status);
        }

        echo $message . PHP_EOL;
    }
}
