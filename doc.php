<html>
    <head>
        <style>
            body, html {
                font-family: arial;
            }
            iframe {
                border: none;
            }
            #arch iframe {
                width: 700px;
                height: 300px;
            }
            #views iframe {
                width: 700px;
                height: 300px;
            }
            #webapp iframe {
                width: 500px;
                height: 300px;
            }
            #arch, #views, #webapp {
                display: inline-block;
            }
        </style>
    </head>
    <body>
        <section id="arch">
            <h1>Architecture globale</h1>
            <iframe src="<?php print $GLOBALS["config"]["webviewsurl"] ?>diagram?datauri=data:text/plain;charset%3Dutf-8,{------ao:[App_iOS]--aa:[App_Andro%C3%AFd]}|[(Orange-<[browser-wa:[web%20app]]||[(Kermit-{{ai:[acc%C3%A8s-int.]--ae:[acc%C3%A8s-ext.]}||[%22petites-annonces%22-{sp:[scripts-PHP]||md:[Mongo-DB]||cr:[cron]}--st:[ressources_statiques]>]})]||ms:[messagerie])],ao-%3Eae,aa-%3Eae,ae-%3Esp,wa-%3Eai,ai-%3Esp,sp-%3Emd,sp-st,cr->md,cr.%3Ems">"</iframe>
        </section>
        <section id="views">
            <h1>Vues web app</h1>
            <iframe src="<?php print $GLOBALS["config"]["webviewsurl"] ?>diagram?datauri=data:text/plain;charset%3Dutf-8,[(ls:[--%3C{nw:[nouveau]|[chercher|||||||||]}-[{%3C{Vends_restaurant_bon_%C3%A9tat...||[]|[]}-%3C%22Cherche_covoiturage...%22-%3C{vt:Vends_terrain_Cesson...|||[]|[]|[]}}]--]---{ed:[{%3C{email%3E--texte%3E--photos%3E}||{%3C[p.coat@orange.com]-%3C[Vends%20terrain%20Cesson]-%3C[[]|[]|[]|drag%27n%20drop]}}--{[Envoyer]|[Annuler]}%3E]||||vw:[%3C{%3C[p.coat@orange.com]--%3CVends_terrain_Cesson--%3C{[]|[]|[]}}--{|||||bt:[(md:[Modifier]|[Supprimer])]}%3E]})]|||{%3Cru:[%22http://petites-annonces%22]---%3Cra:[%22http://petites-annonces/adverts%22]--------%3Cau:[%22http://petites-annonces/adverts/e54a8bc1%22]--%3Cac:[%22http://petites-annonces/adverts/e54a8bc1?code-487ef6d9%22]--%3Ccm:%22Si_code_fourni%22},nw-%3Eed,vt-%3Evw,md-%3Eed,ru-%3Era,ra-%3Els,au-%3Evw,ac-%3Evw,bt.cm">"</iframe>
        </section>
        <section id="webapp">
            <h1>Architecture web app</h1>
            <iframe src="<?php print $GLOBALS["config"]["webviewsurl"] ?>diagram?datauri=data:text/plain;charset%3Dutf-8,user:[(User)]|||[(<{history:[history]||||||||Browser}-{DOM:[DOM-nodes:[nodes]]>||[[{state:[state]-vws:[views]}>||{webapp--res:[resources]}]>||{javascript--cache:[HTTP-cache]}>]})]||[(Server--API:[resources])],user->history,user->DOM,nodes-vws,res-cache,cache->API,history-state,vws-res,vws-state">"</iframe>
        </section>
        <section id="ressources">
            <h1>Ressources HTTP v0.3</h1>
            <pre>
* En dehors de la racine, les URIs ne sont pas décrites explicitement, mais peuvent être récupérées dans les ressources liées.
* Il est possible, mais effectivement déconseillé de coder en dur ces URIs dans le code source des clients.
* Pour les requêtes GET, le header "Accept" doit être utilisé pour spécifier la représentation souhaitée ("text/html" ou "application/json")
* Si le MIME type n'est pas précisé dans la documentation d'une requête GET, cela signifie que le serveur ne tient pas compte du header "Accept".
* Les ressources HTML sont toutes encodées en UTF-8
* Les collection tronquées contiennent un header "Link: xxx; rel=next" contenant l'URI de la suite de la collection
* Les requêtes POST retournent un code "201 Created" en cas de succès, avec un body vide et l'URI de la ressource créée dans le header "Location".
* Les requêtes PUT retournent un code "204 No Content" et un body vide.
* Les requêtes ne correspondant à aucune des méthodes documentées ci-dessus retourneront un code 405 "Method Not Allowed".
* Les ressources sont compatibles CORS. Me contacter pour ajouter des domaines à la liste des origines acceptées.
* contact: philippe.deschaseaux@orange.com


root_URI: la racine (http://petites-annonces.kermit.rd.francetelecom.fr)
------------------------------------------------------------------------

    GET (HTML)          * redirige vers adverts_URI

    GET (JSON)          * renvoie la ressource de base:
                            {
                                adverts: adverts_URI,       // URI de la collection des annonces
                                photos: photos_URI,         // URI de la collection des photos
                                doc: doc_URI                // URI de cette documentation
                                admin: admin_URI
                            }


adverts_URI: la collection des annonces
---------------------------------------

    GET (HTML)          * renvoie la web app

    GET (JSON)          * renvoie une collection (tronquée aux 100 annonces les plus récentes) d'URIs d'annonce
                            [
                                advert_URI,                 // URI d'une annonce de la base
                                ...
                            ]

    POST (multipart)    * ajoute une nouvelle annonce
                            from=string[64 max],            // email du rédacteur de l'annonce
                            text=string[1024 max],          // texte de l'annonce, au format markdown
                            photos=string[??? max]          // ids des photos de l'annonce, séparés par des virgules
                        * renvoie les headers HTTP
                            Location: {advert_URI}
                            Content-Location: {advert_URI}
                            Link: {advert_edition_URI}; rel="editor"
                            X-Edit-Code: {string[8]}        // code d'édition


advert_URI: une annonce
-----------------------

    GET (HTML)          * renvoie la webapp, positionnée sur la fiche de l'annonce
                            edit_code=string[8]             // la webapp se positionne en modification/suppression

    GET (JSON)          * renvoie les propriétés de l'annonce
                            {
                                id: string[8],              // identifiant de l'annonce
                                self: advert_URI,           // URI de cette annonce
                                from: string[64 max],       // email du rédacteur de l'annonce
                                text: string[1024 max],     // texte de l'annonce, au format markdown
                                photos: advert_photos_URI   // URI de la collection des photos de l'annonce
                            }

    PUT (text/plain)    * remplace le texte de l'annonce
                        * nécessite le header HTTP
                            X-Edit-Code: {string[8]}

    DELETE              * supprime l'annonce
                        * nécessite le header HTTP
                            X-Edit-Code: {string[8]}


advert_edition_URI
------------------

    GET (HTML)          * renvoie la webapp, positionnée sur la fiche de l'annonce, et avec le code d'édition pré-rempli


advert_photos_URI: la collection des photos d'une annonce
---------------------------------------------------------

    GET (JSON)          * renvoie la collection des URIs des photos de l'annonce
                            [
                                advert_photo_URI,
                                ...
                            ]

    POST (multipart)    * ajoute une nouvelle photo (jpg ou png) à l'annonce
                        * nécessite le header HTTP
                            X-Edit-Code: {string[8]}
                        * renvoie les headers HTTP
                            Location: {advert_photo_URI}
                            Content-Location: {advert_photo_URI}


advert_photo_URI: une photo
---------------------------

    GET                 * renvoie la photo (image/png ou image/jpeg)

    DELETE              * supprime la photo
                        * nécessite le header HTTP
                            X-Edit-Code: {string[8]}


doc_URI: la documentation
-------------------------

    GET                 * renvoie cette page (text/html)


admin_URI: la webapp d'administration
-------------------------------------

    GET                 * renvoie la webapp d'administration (text/html)
                            protégée par Basic Auth
            </pre>
        </section>
    </body>
</html>
