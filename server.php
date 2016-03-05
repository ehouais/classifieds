<?php
// Initialization --------------------------------------------------------------
function getEnvOr($name, $default) {
    $env = getenv($name);
    return $env ? $env : $default;
}

$GLOBALS["env"] = array(
    "datadir"       => getEnvOr("OPENSHIFT_DATA_DIR", "data/"),
    "mongodburl"    => getEnvOr("OPENSHIFT_MONGODB_DB_URL", "mongodb://127.0.0.1:27017/")
);

include $GLOBALS["env"]["datadir"]."config.php";

// Utilitary functions ---------------------------------------------------------
function dbConnect() {
    $conn = new MongoClient($GLOBALS["env"]["mongodburl"]);
    return $conn->{$GLOBALS["config"]["mongodbname"]};
}

function advert_uri($id) {
    return $GLOBALS["config"]["urlroot"]."adverts/".$id;
}

// Request handlers ------------------------------------------------------------
include "Request.php";

Request::cors($GLOBALS["config"]["cors"]);

// Top resource or web app, depending on "Accept" header
Request::bind("", function() {
    if (Request::accept() == "json") {
        Request::sendAsJson(array(
            "adverts" => $GLOBALS["config"]["urlroot"]."adverts"
        ));
    } else {
        $ad_id = isset($_GET["id"]) ? $_GET["id"] : null;
        $edit_code = isset($_GET["editcode"]) ? $_GET["editcode"] : null;
        include "index.php";
    }
});

// Main collection -------------------------------------------------------------
Request::bind("@^adverts$@", array(
    "GET" => function() {
        $db = dbConnect();
        $data = array();
        foreach ($db->adverts->find() as $key => $value) {
            $advert = array(
                "id"    => $value["short_id"],
                "self"  => advert_uri($value["short_id"]),
                "from"  => $value["from"],
                "text"  => $value["text"]
            );
            if (isset($value["media"])) $advert["media"] = $value["media"];
            $data[] = $advert;
        }
        Request::sendAsJson($data);
    },
    "POST" => function() {
        $text = $_POST["text"];
        $id = md5(uniqid().$text);
        $doc = array(
            "id"        => $id,
            "short_id"  => substr($id, 0, 8),
            "edit_code" => substr($id, 8, 8),
            "from"      => $_POST["from"],
            "text"      => $text
        );
        if (isset($_POST["media"])) $doc["media"] = $media;

        $db = dbConnect();
        $db->adverts->insert($doc);
        $uri = advert_uri($doc["short_id"]);
        header("HTTP/1.1 201 Created");
        header("Access-Control-Expose-Headers: location, content-location");
        header("Location: ".$uri);
        header("Content-Location: ".$uri);
        header("Link: ".$GLOBALS["config"]["urlroot"]."?id=".$doc["short_id"]."&editcode=".$doc["edit_code"]."; rel=\"edit\"");
    }
));

Request::bind("@^adverts/([^/]+)$@", array(
    "GET" => function($matches) {
        list($sel, $id) = $matches;
        $db = dbConnect();
    },
    "PUT" => function($matches) {
        list($sel, $id) = $matches;
        $db = dbConnect();
    },
    "DELETE" => function($matches) {
        list($sel, $id) = $matches;

        $db = dbConnect();
        $advert = $db->adverts->findOne(array("short_id" => $id));
        if (!$advert) Request::error404();

        $headers = getallheaders();
        if (!isset($headers["X-Edit-Code"]) || $headers["X-Edit-Code"] != $advert["edit_code"]) {

        }
    }
));

// Media server ----------------------------------------------------------------
Request::bind("@^media$@", array(
    "POST" => function() {
        if (empty($_FILES)) Request::error400("No upload data");

        $tmpName = $_FILES['file']['tmp_name'];

        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpName);
        if ($mime != "image/jpeg" && $mime != "image/png") Request::error400("Only jpg and png files allowed");

        $id = md5(uniqid().$_FILES['file']['name']);
        $filepath = $GLOBALS["env"]["datadir"]."media/".$id;
        if (!move_uploaded_file($tmpName, $filepath)) Request::error500("Upload error");

        $uri = $GLOBALS["config"]["urlroot"]."media/".$id;
        header("HTTP/1.1 201 Created");
        header("Access-Control-Expose-Headers: location, content-location");
        header("Location: ".$uri);
        header("Content-Location: ".$uri);
    }
));

Request::bind("@^media/([^/]+)$@", array(
    "GET" => function($matches) {
        list($sel, $id) = $matches;

        $filepath = $GLOBALS["env"]["datadir"]."media/".$id;
        if (!file_exists($filepath)) Request::error404();

        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $filepath);
        header("Content-Type: ".$mime);
        readfile($filepath);
    }
));

// Miscellaneous resources -----------------------------------------------------
Request::bind("@^doc$@", function() {
    header("Content-Type: text/plain; charset=utf-8");
    include "doc.php";
});

Request::bind("@^admin$@", array(
    "*" => function() {
        $_SERVER['PHP_SELF'] = $GLOBALS["config"]["urlroot"]."admin";
        $server = array($GLOBALS["env"]["mongodburl"]);

        include "mongodbadmin.php";
    }
));

// Not a valid resource --------------------------------------------------------
Request::error404();
?>
