<?php
// Initialization --------------------------------------------------------------
function getEnvOr($name, $default) {
    $env = getenv($name);
    return $env ? $env : $default;
}

$GLOBALS["env"] = array(
    "datadir" => getEnvOr("OPENSHIFT_DATA_DIR", "data/"),
    "mongodburl" => getEnvOr("OPENSHIFT_MONGODB_DB_URL", "mongodb://127.0.0.1:27017/"),
);

include $GLOBALS["env"]["datadir"]."config.php";
include "Request.php";

// Utilitary functions ---------------------------------------------------------
function dbConnect() {
    $conn = new MongoClient($GLOBALS["env"]["mongodburl"]);
    return $conn->{$GLOBALS["config"]["mongodbname"]};
}

URI::setRoot($GLOBALS["config"]["urlroot"]);

$root_uri = new URI("");
$adverts_uri = new URI("adverts");
$advert_uri = new URI("adverts/{advert_id}");
$advert_photos_uri = new URI("adverts/{advert_id}/photos");
$advert_photo_uri = new URI("adverts/{advert_id}/photos/{photo_id}");
$doc_uri = new URI("doc");
$admin_uri = new URI("admin");

function webApp($context) {
    extract($context);
    header("Vary: Accept");
    include "index.php";
}

function advertDocOr404($db, $id) {
    $doc = $db->adverts->findOne(array("short_id" => $id));
    if (!$doc) Request::error404();
    return $doc;
}

function checkEditCode($advert) {
    $headers = getallheaders();
    $hname = $GLOBALS["config"]["codeheader"];
    if (!isset($headers[$hname]) || $headers[$hname] != $advert["edit_code"])
        Request::error("HTTP/1.1 403 Forbidden", "Vous devez fournir un code d'édition valide dans le header \"".$hname."\".");
}

function photoFilepath($id) {
    return $GLOBALS["env"]["datadir"]."photos/".$id;
}

Request::cors($GLOBALS["config"]["cors"]);

// Request handlers ------------------------------------------------------------

// Top resource or web app, depending on "Accept" header
Request::match($root_uri, function() use ($adverts_uri, $doc_uri, $admin_uri) {
    if (Request::accept() == "json") {
        Request::sendAsJson(array(
            "adverts" => $adverts_uri->value(),
            "doc" => $doc_uri->value(),
            "admin" => $admin_uri->value(),
        ));
    } else {
        webApp(array(
            "ad_id" => null,
            "edit_code" => null,
        ));
    }
});

// Main collection -------------------------------------------------------------
Request::match($adverts_uri, array(
    "GET" => function() use ($advert_uri) {
        if (Request::accept() == "json") {
            $db = dbConnect();
            $data = array();
            foreach ($db->adverts->find()->limit(100) as $value) {
                $data[] = $advert_uri->value(array("advert_id" => $value["short_id"]));
            }
            Request::sendAsJson($data);
        } else {
            webApp(array(
                "ad_id" => null,
                "edit_code" => null,
            ));
        }
    },
    "POST" => function() use ($advert_uri) {
        $text = $_POST["text"];
        $id = md5(uniqid().$text);
        $doc = array(
            "id" => $id,
            "short_id" => substr($id, 0, 8),
            "edit_code" => substr($id, 8, 8),
            "from" => $_POST["from"],
            "text" => $text,
        );

        $db = dbConnect();
        $db->adverts->insert($doc);
        $uri = $advert_uri->value(array("advert_id" => $doc["short_id"]));
        header("HTTP/1.1 201 Created");
        header("Access-Control-Expose-Headers: location, content-location, link");
        header("Location: ".$uri);
        header("Content-Location: ".$uri);
        header("Link: ".$uri."&code=".$doc["edit_code"]."; rel=\"editor\"");
        header($GLOBALS["config"]["codeheader"].": ".$doc["edit_code"]);
    }
));

Request::match($advert_uri, array(
    "GET" => function($match) use ($advert_uri) {
        $advert = advertDocOr404(dbConnect(), $match["advert_id"]);

        if (Request::accept() == "json") {
            Request::sendAsJson(array(
                "id" => $advert["short_id"],
                "self" => $GLOBALS["advert_uri"]->value(array("advert_id" => $advert["short_id"])),
                "from" => $advert["from"],
                "text" => $advert["text"],
                "photos" => $GLOBALS["advert_photos_uri"]->value(array("advert_id" => $advert["short_id"])),
                "collection" => $GLOBALS["adverts_uri"]->value(),
            ));
        } else {
            webApp(array(
                "uri" => $advert_uri->value(array("advert_id" => $advert["short_id"])),
                "code" => isset($_GET["code"]) ? $_GET["code"] : ""
            ));
        }
    },
    "PUT" => function($match) {
        $db = dbConnect();
        $advert = advertDocOr404($db, $match["advert_id"]);

        checkEditCode($advert);

        $db->adverts->update(array("short_id" => $match["advert_id"]), array("\$set" => array(
            "text" => Request::body(),
        )));
        header("HTTP/1.1 204 No Content");
    },
    "DELETE" => function($match) use ($advert_uri) {
        $db = dbConnect();
        $advert = advertDocOr404($db, $match["advert_id"]);

        checkEditCode($advert);

        $db->adverts->remove(array("short_id" => $match["advert_id"]));
        header("HTTP/1.1 204 No Content");
    },
));

Request::match($advert_photos_uri, array(
    "GET" => function($match) use ($advert_uri, $advert_photo_uri) {
        $advert = advertDocOr404(dbConnect(), $match["advert_id"]);
        $ad_uri = $advert_uri->value(array("advert_id" => $advert["short_id"]));

        if (Request::accept() == "json") {
            $uris = array();
            if (isset($advert["photos"])) {
                forEach($advert["photos"] as $photo_id) {
                    $uris[] = $advert_photo_uri->value(array("advert_id" => $advert["short_id"], "photo_id" => $photo_id));
                }
            }
            header("Link: ".$ad_uri."; rel=\"parent\"");
            Request::sendAsJson($uris);
        } else {
            header("Location: ".$ad_uri);
        }
    },
    "POST" => function($match) use ($advert_photo_uri) {
        $db = dbConnect();
        $advert = advertDocOr404($db, $match["advert_id"]);

        checkEditCode($advert);

        if (empty($_FILES)) Request::error400("No upload data");

        $tmpName = $_FILES['file']['tmp_name'];

        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpName);
        if ($mime != "image/jpeg" && $mime != "image/png") Request::error400("Only jpg and png files allowed");

        $id = md5(uniqid().$_FILES['file']['name']);
        $filepath = photoFilepath($id);
        if (!move_uploaded_file($tmpName, $filepath)) Request::error500("Upload error");

        $advert["photos"][] = $id;
        $db->adverts->update(array("short_id" => $advert["short_id"]), array("\$set" => array("photos" => $advert["photos"])));

        $uri = $advert_photo_uri->value(array("advert_id" => $advert["short_id"], "photo_id" => $id));
        header("HTTP/1.1 201 Created");
        header("Access-Control-Expose-Headers: location, content-location");
        header("Location: ".$uri);
        header("Content-Location: ".$uri);
    }
));

Request::match($advert_photo_uri, array(
    "GET" => function($match) use ($advert_uri) {
        $advert = advertDocOr404(dbConnect(), $match["advert_id"]);
        $filepath = photoFilepath($match["photo_id"]);
        if (!file_exists($filepath)) Request::error404();

        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $filepath);
        header("Content-Type: ".$mime);
        header("Link: ".$advert_uri->value(array("advert_id" => $advert["short_id"]))."; rel=\"parent\"");
        readfile($filepath);
    },
    "DELETE" => function($match) {
        $db = dbConnect();
        $advert_id = $match["advert_id"];
        $advert = advertDocOr404($db, $advert_id);
        $photo_id = $match["photo_id"];
        $filepath = photoFilepath($photo_id);
        if (!file_exists($filepath)) Request::error404();

        checkEditCode($advert);

        $key = array_search($photo_id, $advert["photos"]);
        if ($key !== false) unset($advert["photos"][$key]);
        $db->adverts->update(array("short_id" => $advert_id), array("\$set" => array("photos" => $advert["photos"])));
        unlink($filepath);
        header("HTTP/1.1 204 No Content");
    }
));

// Miscellaneous resources -----------------------------------------------------
Request::match($doc_uri, function() {
    header("Content-Type: text/html; charset=utf-8");
    include "doc.php";
});

Request::match($admin_uri, array(
    "*" => function() use ($admin_uri) {
        $_SERVER['PHP_SELF'] = $admin_uri->value();
        $server = array($GLOBALS["env"]["mongodburl"]);

        include "mongodbadmin.php";
    },
));

// Not a valid resource --------------------------------------------------------
Request::error404();
?>
