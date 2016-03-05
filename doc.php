racine: http://petites-annonces.kermit.rd.francetelecom.fr

/adverts        [GET (JSON)]        : récupère la collection complète des annonces
                [POST (multipart)]  : ajoute une nouvelle annonce (from={email},text={markdown string},media={list of URLs})
/adverts/{id}   [GET (JSON)]        : récupère une annonce
                [PUT (multipart)]   : remplace une annonce
                [DELETE]            : supprime une annonce

/media          [POST (multipart)]  : ajoute une nouvelle photo (jpg ou png)
/media/{id}     [GET]               : récupère une photo

/doc            [GET]               : cette page

Remarques:
* contact: philipp.deschaseaux@orange.com
* Les ressources sont compatibles CORS. Me contacter pour ajouter des domaines à la liste des origines acceptées.
* Les requêtes POST retournent un code "HTTP/1.1 201 Created" en cas de succès, avec l'URI de la ressource créée dans le header "Location".
