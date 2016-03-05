<?php
class Request {
    private static $method;
    private static $accept;
    private static $body;
    private static $root;
    private static $path;
    private static function toJson($data) {
        return defined('JSON_UNESCAPED_SLASHES') ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : str_replace("\/", "/", json_encode($data));
    }
    private static function prettifyJson($json) {
        $result = '';
        $level = 0;
        $prev_char = '';
        $in_quotes = false;
        $ends_line_level = NULL;
        $json_length = strlen($json);

        for ($i = 0; $i < $json_length; $i++) {
            $char = $json[$i];
            $new_line_level = NULL;
            $post = "";
            if ($ends_line_level !== NULL) {
                $new_line_level = $ends_line_level;
                $ends_line_level = NULL;
            }
            if ($char === '"' && $prev_char != '\\') {
                $in_quotes = !$in_quotes;
            } else if (!$in_quotes) {
                switch ($char) {
                case '}': case ']':
                    $level--;
                    $ends_line_level = NULL;
                    $new_line_level = $level;
                    break;

                case '{': case '[':
                    $level++;
                case ',':
                    $ends_line_level = $level;
                    break;

                case ':':
                    $post = " ";
                    break;

                case " ": case "\t": case "\n": case "\r":
                    $char = "";
                    $ends_line_level = $new_line_level;
                    $new_line_level = NULL;
                    break;
                }
            }
            if ($new_line_level !== NULL) {
                $result .= "\n".str_repeat("    ", $new_line_level);
            }
            $result .= $char.$post;
            $prev_char = $char;
        }

        return $result;
    }

    // Public functions -------------------------------------------------------

    public static function init() {
        // HTTP method
        self::$method = strtoupper($_SERVER["REQUEST_METHOD"]);
        // HTTP accept
        self::$accept = "any";
        if (isset($_SERVER["HTTP_ACCEPT"])) {
            if (strpos($_SERVER["HTTP_ACCEPT"], "application/json") !== false) {
                self::$accept = "json";
            } elseif (strpos($_SERVER["HTTP_ACCEPT"], "*/*") !== false || strpos($_SERVER["HTTP_ACCEPT"], "text/html") !== false) {
                self::$accept = "html";
            }
        }
        // HTTP body
        self::$body = file_get_contents("php://input");
        // URL root
        self::$root = "http://".$_SERVER["SERVER_NAME"];
        // Resource path
        $parts = explode("?", substr($_SERVER["REQUEST_URI"], 1));
        self::$path = $parts[0];
        if (substr(self::$path, -1) == "/") self::$path = substr(self::$path, 0, -1);
    }
    public static function method() {
        return self::$method;
    }
    public static function accept() {
        return self::$accept;
    }
    public static function body() {
        return self::$body;
    }
    public static function root() {
        return self::$root;
    }
    public static function error($header, $msg) {
        header($header);
        print $msg;
        exit;
    }
    public static function error400($msg) {
        self::error("HTTP/1.1 400 Bad Request", $msg);
    }
    public static function error401($type, $realm, $msg) {
        header("WWW-Authenticate: ".$type." realm=\"".$realm."\"");
        self::error("HTTP/1.1 401 Not Authorized", $msg);
    }
    public static function error404() {
        self::error("HTTP/1.1 404 Not Found", "");
    }
    public static function error500($msg) {
        self::error("HTTP/1.1 500 Internal Server Error", $msg);
    }
    public static function cors($allowed) {
        if (isset($_SERVER["HTTP_ORIGIN"])) {
            header("Vary: Origin", false);
            $domain = str_replace("http://", "", $_SERVER['HTTP_ORIGIN']);
            foreach ($allowed as $pattern) {
                if (preg_match("/".str_replace(array(".", "*"), array("\.", "[^\.]+"), $pattern)."/", $domain, $matches)) {
                    header("Access-Control-Allow-Origin: ".$_SERVER['HTTP_ORIGIN']);
                    header("Access-Control-Allow-Credentials: true");
                    header("Vary: Origin");
                    break;
                }
            }
        }
        if (self::$method == "OPTIONS") {
            if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"]))
                header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

            if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"]))
                header("Access-Control-Allow-Headers: ".$_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"]);

            exit;
        }
    }
    public static function ifModifiedSince($timestamp, $cb) {
        if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) && strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]) >= $timestamp) {
            header("HTTP/1.0 304 Not Modified");
        } else {
            header("HTTP/1.1 200 OK");
            header("Last-Modified: ".gmdate("D, d M Y H:i:s", $timestamp)." GMT");
            $cb();
        }
    }
    public static function bind($pattern, $handlers, $context = null) {
        if (!is_array($handlers)) {
            $handlers = array("GET" => $handlers);
        }
        $matches = null;
        if (($pattern == "" && self::$path == "") || ($pattern && preg_match($pattern, self::$path, $matches))) {
            if (isset($handlers[self::$method])) $handler = $handlers[self::$method];
            elseif (self::$method != "OPTIONS" && isset($handlers["*"])) $handler = $handlers["*"];

            if (isset($handler)) {
                try {
                    ob_start();
                    if (is_callable($handler)) {
                        $handler($matches);
                    } else {
                        if ($context) extract($context);
                        include $handler;
                    }
                    ob_end_flush();
                } catch (Exception $e) {
                    ob_end_clean();
                    self::error500($e->getMessage());
                }
                exit;
            } elseif (self::$method != "OPTIONS") {
                self::error("HTTP/1.1 405 Method Not Allowed", "");
            }
        }
    }
    public static function sendJson($json) {
        header("Vary: Accept");
        if (self::$accept == "html") {
            header("Content-Type: text/html; charset=utf-8");
            print "<pre>".preg_replace("/\"(https?:\/\/[^\"]+)\"/", "<a href=\"$1\">$1</a>", self::prettifyJson($json))."</pre>";
        } else {
            header("Content-Type: application/json; charset=utf-8");
            print $json;
        }
    }
    public static function sendAsJson($data) {
        self::sendJson(self::toJson($data));
    }
    public static function filter($list, $propnames) {
        foreach($list as $id => $data) {
            foreach($propnames as $name) {
                if (isset($_GET[$name]) && isset($data[$name]) && $data[$name] != $_GET[$name]) {
                    unset($list[$id]);
                    break;
                }
            }
        }
        return $list;
    }
    public static function filterProperties($list, $default) {
        $items = array();
        $props = isset($_GET["props"]) ? explode(",", $_GET["props"]) : null;
        foreach($list as $id => $data) {
            if ($props) {
                $item = array();
                foreach($props as $name) {
                    if (isset($data[$name])) $item[$name] = $data[$name];
                }
            } else {
                $item = $default($id);
            }
            $items[] = $item;
        }
        return $items;
    }
}

Request::init();
?>
