var pg = require('pg');
var nconf = require('nconf');
var request = require("request");
var winston = require('winston');
winston.add(winston.transports.File, {filename: '/var/www/geocloud2/public/logs/meta2ckan.log'});

nconf.argv();
var db = (nconf.get()._[0]);
var host = nconf.get("host") || "127.0.0.1";
var ckanHost = nconf.get("ckan-host") || "127.0.0.1";
var gc2Host = nconf.get("gc2-host") || "127.0.0.1";
var user = nconf.get("user") || "postgres";
var key = nconf.get("key") || null;

if (nconf.get("help") || !db) {
    console.log("usage:");
    console.log("  pg2es.js database [options]");
    console.log("Options:");
    console.log("  --host       PostGreSQL host. Default 127.0.0.1");
    console.log("  --user       PostGreSQL user. Default postgres");
    console.log("  --ckan-host    Ckan host. Default 127.0.0.1");
    console.log("  --gc2-host    GC2 host. Default 127.0.0.1");
    console.log("  --key    GC2 api key");
    process.exit(1);
}

/**
 * @type {{user: (any), database: *, host: (any), port: number, max: number, idleTimeoutMillis: number}}
 */
var config = {
    user: user,
    database: db,
    host: host,
    port: 5432,
    max: 1, // ONLY ONE CLIENT IN THE POOL
    idleTimeoutMillis: 3000 // how long a client is allowed to remain idle before being closed
};

/**
 * @param client
 */
var start = function (client) {
    console.log("Listen on database: " + db + "@" + host + " with user " + user);
    client.on('notification', function (msg) {
        var split = msg.payload.split(","), url;
        url = "http://" + ckanHost + "/api/v1/ckan/" + db + "?id=" + split[2] + "&host=" + gc2Host;
        if (key) {
            url = url + "&key=" + key;
        }
        console.log(url);
        request.get(url, function (err, res, body) {
            if (!err) {
                var resultsObj = JSON.parse(body);
                winston.log('info', resultsObj.success);
            } else {
                winston.log('error', err);

            }
        });

    });
    client.query("LISTEN _gc2_notify_meta");
};

/**
 * @type {pg.Pool}
 */
var pool = new pg.Pool(config);

pool.connect(function (err, client, done) {
    if (err) {
        console.log(err);
    } else {
        start(client);
    }
});

pool.on('error', function (err, client) {
    console.error(err.message);
    pool.connect(function (err, client, done) {
        if (!err) {
            start(client);
        }
    });
});
