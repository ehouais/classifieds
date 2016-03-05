<!doctype HTML>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
        <title>Petites annonces</title>
        <script src="//cdnjs.cloudflare.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
        <script src="//cdnjs.cloudflare.com/ajax/libs/sjcl/1.0.0/sjcl.min.js"></script>
        <script src="//cdnjs.cloudflare.com/ajax/libs/dropzone/4.3.0/min/dropzone.min.js"></script>
        <link href="//cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.0/css/bootstrap.min.css" rel="stylesheet">
        <style>
            .subcontainer {
                width: 75%;
                margin: auto;
            }
            #form {
                padding: 10px;
                margin-bottom: 5px;
                border-radius: 5px;
            }
            #form textarea {
                width: 100%;
                height: 200px;
            }
            #adverts {
                margin-top: 20px;
            }
            .advert button {
                margin: -1px -6px;
            }
            .advert button .glyphicon {
                margin-top: 2px;
            }
            #form, #new_buttons, #create_buttons, #edit_buttons {
                display: none;
            }
            #advert {
                padding: 10px;
                margin-bottom: 5px;
            }
            #advert .media {
                margin-top: 20px;
            }
            #advert img {
                max-height: 200px;
            }
            .dropzone {
                border: 2px dashed #0087F7;
                border-radius: 5px;
                background: white;
                min-height: 100px;
                padding: 20px;
            }
            #form .row {
                margin-bottom: 10px;
            }
            #form label {
                text-align: right;
                width: 100%;
                margin: 3px 0;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="page-header">
                <h1>Petites annonces</h1>
            </div>
            <div class="subcontainer">
                <div id="advert" class="panel panel-default">
                    <div class="from"></div>
                    <div class="text"></div>
                    <div class="media"></div>
                </div>
                <div id="new_buttons">
                    <div class="row">
                        <div class="col-md-3">
                            <button id="new_btn" class="btn btn-success" type="submit">Nouvelle annonce</button>
                        </div>
                        <div class="col-md-9">
                            <input type="text" class="form-control" placeholder="Texte à rechercher"/>
                        </div>
                    </div>
                </div>
                <div id="form">
                    <div class="row">
                        <div class="col-md-1"><label for="from">De</label></div>
                        <div class="col-md-11"><input class="form-control" type="text" name="from"/></div>
                    </div>
                    <div class="row">
                        <div class="col-md-1"><label for="text">Texte</label></div>
                        <div class="col-md-11"><textarea class="form-control" type="text" name="text"></textarea></div>
                    </div>
                    <div class="row">
                        <div class="col-md-1"><label for="media">Media</label></div>
                        <div class="col-md-11"><form name="media" action="/media" class="dropzone" id="dropzone"></form></div>
                    </div>
                    <div class="row">
                        <div class="col-md-1"></div>
                        <div class="col-md-6">
                            <button id="create_btn" class="btn btn-success pull-left" type="submit">Créer</button>
                        </div>
                        <div class="col-md-5">
                            <button id="ccancel_btn" class="btn btn-default pull-right" type="submit">Annuler</button>
                        </div>
                    </div>
                </div>
                <div id="edit_buttons">
                    <button class="btn btn-success" type="submit">Mettre à jour</button>
                    <button class="btn btn-danger" type="submit">Supprimer</button>
                    <button id="ecancel_btn" class="btn btn-default pull-right" type="submit">Annuler</button>
                </div>
                <div id="view_buttons">
                    <button id="vcancel_btn" class="btn btn-default pull-right" type="submit">OK</button>
                </div>
                <div id="adverts" class="list-group">
                    <a href="" class="list-group-item advert template">
                        <span class="text"></span>
                    </a>
                </div>
            </div>
        </div>
        <script>
            var adverts,
                id = '<?php print $ad_id ?>',
                editcode = '<?php print $edit_code ?>',
                root = '<?php print $GLOBALS["config"]["urlroot"] ?>adverts',
                $alst = $('#adverts'),
                $atpl = $('.advert.template', $alst).detach().removeClass('template'),
                $advert = $('#advert'),
                refresh = function() {
                    $.getJSON(root).done(function(ads) {
                        $alst.empty();
                        ads.forEach(function(advert) {
                            advert.$dom = $atpl.clone()
                              .attr('href', advert.self)
                              .data('id', advert.id)
                              .find('.text').html(advert.text+' ('+advert.from+')')
                              .parent().appendTo($alst);
                        });
                        adverts = ads.reduce(function(map, advert) {
                            map[advert.id] = advert;
                            return map;
                        }, {});
                    });
                },
                setState = function(state) {
                    // 'list', 'new', 'item'
                    $('#form').toggle(state == 'new' || (state == 'item' && !!editcode));
                    $('#new_buttons').toggle(state == 'list');
                    $('#edit_buttons').toggle(state == 'item' && !!editcode);
                    $alst.toggle(state == 'list');
                    $advert.toggle(state == 'item' && !editcode);
                    $('#view_buttons').toggle(state == 'item' && !editcode);
                };

            $('#ccancel_btn, #ecancel_btn, #vcancel_btn').on('click', function() { setState('list'); });

            // Create ----------------------------------------------------------
            $('#new_btn').on('click', function() { setState('new'); });

            $('#create_btn').on('click', function() {
                $.post(root, {
                    from: $('#form input[name="from"]').val(),
                    text: $('#form textarea').val(),
                    media: dropzone.getAcceptedFiles().map(function(file) {
                        return file.xhr.getResponseHeader('Location')
                    })
                }).done(function(data, status, request) {
                    var edit_url = request.getResponseHeader('Link').split(';').find(function(part) {
                        return part.trim().substr(0, 4) != 'rel=';
                    });
                    console.log(edit_url);

                    refresh();
                    setState('list');
                });
            });

            $('#create_btn, #ccancel_btn').on('click', function() {
                $('#form input').val('');
                $('#form textarea').val('');
                dropzone.removeAllFiles();
            });

            // Drag and drop
            Dropzone.autoDiscover = false;

            var dropzone = new Dropzone("#form .dropzone", {
                maxFilesize: 0.5, // MB
                acceptedFiles: 'image/jpeg,image/png'
            });

            // View ------------------------------------------------------------
            $alst.on('click', '.advert', function(e) {
                id = $(e.currentTarget).data('id');
                $('.from', $advert).text(adverts[id].from);
                $('.text', $advert).text(adverts[id].text);
                adverts[id].media && $('.media', $advert).append(adverts[id].media.map(function(uri) {
                    return $('<img/>').attr('src', uri);
                }));
                setState('item');
                return false;
            });

            $('#vcancel_btn').on('click', function() {
                $('#advert .from').text('');
                $('#advert .text').text('');
                $('#advert .media').empty();
            });

            // Initialization --------------------------------------------------
            refresh();
            setState(id ? 'item' : 'list');
        </script>
    </body>
</html>
