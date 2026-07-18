#!/usr/bin/env python3
import os, shutil, sys

APP = "/home/krocerco/public_html/green7.app"
STAGE = "/home/krocerco/letsync_stage"

def backup(path):
    b = path + ".letsync.bak"
    if os.path.exists(path) and not os.path.exists(b):
        shutil.copy2(path, b)
        print("backup:", b)

def copy_tree():
    for root, _, files in os.walk(STAGE):
        for fn in files:
            src = os.path.join(root, fn)
            rel = os.path.relpath(src, STAGE)
            dst = os.path.join(APP, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copy2(src, dst)
            print("copied:", rel)

def patch_database():
    path = os.path.join(APP, "config/database.php")
    src = open(path).read()
    if "'opencart'" in src:
        print("database.php already patched"); return
    backup(path)
    block = """        'opencart' => [
            'driver' => 'mysql',
            'host' => env('OC_DB_HOST', '127.0.0.1'),
            'port' => env('OC_DB_PORT', '3306'),
            'database' => env('OC_DB_DATABASE'),
            'username' => env('OC_DB_USERNAME'),
            'password' => env('OC_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => env('OC_DB_PREFIX', 'ocdh_'),
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
        ],

"""
    anchor = "        'mariadb' => ["
    if anchor not in src:
        print("ERROR: mariadb anchor not found"); sys.exit(1)
    src = src.replace(anchor, block + anchor, 1)
    open(path, "w").write(src)
    print("patched database.php")

def patch_bootstrap():
    path = os.path.join(APP, "bootstrap/app.php")
    src = open(path).read()
    changed = False
    if "'letsync'" not in src:
        backup(path)
        alias_anchor = "'hub.context' => ResolveHubContext::class,"
        if alias_anchor not in src:
            print("ERROR: alias anchor not found"); sys.exit(1)
        src = src.replace(alias_anchor, alias_anchor + "\n                    'letsync' => \\App\\Http\\Middleware\\VerifyLetsyncToken::class,", 1)
        changed = True
    if "routes/letsync.php" not in src:
        route_anchor = "->group(base_path('routes/design.php'));"
        if route_anchor not in src:
            print("ERROR: route anchor not found"); sys.exit(1)
        addition = route_anchor + "\n\n            Route::middleware(['letsync'])\n                ->group(base_path('routes/letsync.php'));"
        src = src.replace(route_anchor, addition, 1)
        changed = True
    if changed:
        open(path, "w").write(src)
        print("patched bootstrap/app.php")
    else:
        print("bootstrap/app.php already patched")

def patch_env():
    path = os.path.join(APP, ".env")
    src = open(path).read()
    if "LETSYNC_TOKEN" in src:
        print(".env already patched"); return
    backup(path)
    extra = """

# Letsync (OpenCart -> DayOneMart sync)
LETSYNC_TOKEN=dd697b8a04c27bcc0b912aa0647bc563bae6e54edcd9a37739ddea4647b2ae16
LETSYNC_MODULE_ID=1
LETSYNC_OC_LANGUAGE_ID=1
LETSYNC_IMAGE_BASE_URL=https://www.green7.ae/image
LETSYNC_IMAGE_DISK=public
LETSYNC_QUEUE=letsync
OC_DB_HOST=127.0.0.1
OC_DB_PORT=3306
OC_DB_DATABASE=krocerco_ocar925
OC_DB_USERNAME=krocerco_ocar925
OC_DB_PASSWORD="4p73iS(rD]"
OC_DB_PREFIX=ocdh_
"""
    if not src.endswith("\n"):
        src += "\n"
    open(path, "w").write(src + extra)
    print("patched .env")

if __name__ == "__main__":
    copy_tree()
    patch_database()
    patch_bootstrap()
    patch_env()
    print("DEPLOY DONE")
