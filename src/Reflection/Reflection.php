<?php

namespace Crayoon\HyperfGrpc\Reflection;

use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\Utils\Str;
use Psr\Container\ContainerInterface;

class Reflection implements ServerReflectionInterface {

    protected ConfigInterface $config;

    protected DispatcherFactory $dispatcherFactory;

    protected array $servers = [];
    protected array $files = [];
    protected array $baseProtoFiles = [];

    public function __construct(protected ContainerInterface $container) {
        $this->config            = $this->container->get(ConfigInterface::class);
        $this->dispatcherFactory = $this->container->get(DispatcherFactory::class);
        //获取服务
        $this->servers        = $this->servers();
        $this->baseProtoFiles = $this->getProtoFilePathsByClass($this->config->get("grpc.reflection.base_class", []));
    }

    /**
     * @param ServerReflectionRequest $request
     * @return ServerReflectionResponse
     */
    public function serverReflectionInfo(ServerReflectionRequest $request): ServerReflectionResponse {
        $resp = new ServerReflectionResponse();
        if ($this->config->get("grpc.reflection.enable") !== true) {
            return $resp;
        }
        $resp->setOriginalRequest($request);
        switch ($request->getMessageRequest()) {
            case "list_services":
                $servers = [];
                foreach ($this->servers as $server => $files) {
                    $servers[] = (new ServiceResponse())->setName($server);
                }
                $resp->setListServicesResponse(
                    (new ListServiceResponse())->setService($servers)
                );
                break;
            case "file_containing_symbol":
                list($files, $symbol) = [[], $request->getFileContainingSymbol()];
                if (isset($this->servers[$symbol]) && is_array($this->servers[$symbol])) {
                    foreach (array_merge($this->servers[$symbol], $this->baseProtoFiles) as $filePath) {
                        $files[] = $this->getProtoFileContent($filePath);
                    }
                }
                // 设置到响应
                $resp->setFileDescriptorResponse(
                    (new FileDescriptorResponse())->setFileDescriptorProto($files)
                );
                break;
        }
        return $resp;
    }

    private function servers(): array {
        $routes   = $this->dispatcherFactory
            ->getRouter($this->config->get("grpc.register.server_name", "grpc"))
            ->getData();
        $services = [];
        /**
         * @var Handler $handler
         */
        if (!empty($routes) && isset($routes[0]['POST'])) foreach ($routes[0]['POST'] as $handler) {
            $service = current(explode("/", trim($handler->route, "/")));
            if (!isset($services[$service])) {
                if (isset($handler->options['protobuf_class']) && !empty($handler->options['protobuf_class'])) {
                    //指定获取
                    $files = $this->getProtoFilePathsByClass(
                        is_array($handler->options['protobuf_class']) ? $handler->options['protobuf_class'] : [$handler->options['protobuf_class']]
                    );
                } else {
                    //自动获取
                    $files = $this->getProtoFilePathsByServer($service);
                }
                !empty($files) && $services[$service] = $files;
            }
        }
        return $services;
    }

    private function getProtoFilePathsByServer(string $serverName): array {
        $pattern        = $this->config->get("grpc.reflection.route_to_proto_pattern", "");
        $serverName     = empty($pattern) ? $serverName : Str::match($pattern, $serverName);
        $protoFilePaths = [];
        $basePath       = $this->config->get("grpc.reflection.path", "app/Grpc/GPBMetadata");
        foreach (explode(".", $serverName) as $item) {
            $file = sprintf("%s/%s.php", $basePath, Str::studly($item));
            if (!in_array($file, $protoFilePaths) && file_exists($file)) {
                $protoFilePaths[] = $file;
            }
        }
        return $protoFilePaths;
    }

    private function getProtoFileContent(string $filePath) {
        if (!isset($this->files[$filePath])) {
            // 读取
            $file = file_get_contents($filePath);
            // 获取proto生成的内容 todo 带点玄学，需要优化
            $start                  = strpos($file, "'", 121) + 1;
            $end                    = strpos($file, "'", $start);
            $file                   = substr($file, $start, $end - $start);
            $file                   = str_replace('\\\\', "\\", $file);
            $file                   = str_replace(substr($file, 1, 3), "", $file);
            $this->files[$filePath] = $file;
        }
        return $this->files[$filePath];
    }

    private function getProtoFilePathsByClass(array $protoClass): array {
        $files = [];
        foreach ($protoClass as $class) {
            try {
                $path = (new \ReflectionClass($class))->getFileName();
                if ($path) {
                    $files[] = $path;
                    continue;
                };
            } catch (\ReflectionException $e) {
            }
            // google proto file
            if (str_contains($class, 'Google\Protobuf')) {
                $files[] = "vendor/google/protobuf/src/" . str_replace("\\", "/", $class . ".php");
            }
        }
        return $files;
    }
}