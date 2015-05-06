# memcached-slabs
PHP CLI tool to analyze Memcached data profile and preallocate slabs to avoid evictions


After long usage memcached can run into many evictions problem due to exhausting memory on large entites.

 

The purpose of this tool

*    Analyze slabs profile, find slabs with evictions
*    Find and save for investigation most memory consuming entities for each slab (top entities)
*    Generate slabs preallocation profile based on current evictions
*    Preallocate slabs to avoid evictions on smaller entites (manual or automatic)
*    Fill/delete memcached with random data for testing
*    Tool uses raw memcached protocol via fsockopen except saving top entities (you require Memcached extension for that)

```
Usage:
    php memcached-slabs.php [options] host1:port1 [host2:port2 ...]
Options:
    -r generate html report
    -p <size:count> preallocate slabs by setting/deleting
    -ps <size:count> preallocate slabs by setting (no deleting)
    -e  process only slabs with evictions-e
    -k  save dumped keys
    -f <count:min_length:max_length:min_ttl:max_ttl:key_prefix> fill memcached with random data
    -d <count:key_prefix> delete random data from memcached
    -s  skip analyzing keys
    -ste <count> save top size entities per key group count (print_r'ed), default 10
    -g <key> get key value (raw protocol)
    -gp <key> print_r key value (Memcached extension required)

Examples:

# preallocate space for 200k of 160 bytes records and 100k of 350 bytes records at myhost:11211 and myhost2:11011
php memcached-slabs.php -p 160:200000 -p 350:100000 myhost:11211 myhost2:11011

# fetch raw values of MY_KEY at myhost:11211 and myhost2:11011
php memcached-slabs.php -g MY_KEY myhost:11211 myhost2:11011

# view print_r values of MY_KEY and MY_KEY2 at myhost:11211 retrieved via Memcached ext
php memcached-slabs.php -gp MY_KEY -gp MY_KEY2 myhost:11211 myhost2:11011

# generate slabs allocation html reports for myhost:11211 and myhost2:11011 (faster, without keys statistics)
php memcached-slabs.php -r -s myhost:11211 myhost2:11011

# generate slabs allocation html reports for myhost:11211 and myhost2:11011 (slower, with keys statistics)
php memcached-slabs.php -r myhost:11211 myhost2:11011

# fill 192.168.59.105:11211 with 50000 record with random size between 50 and 100,
# zero ttl and names SMALL_0 to SMALL_49999
php memcached-keys-stats.php -f 50000:50:100:0:0:SMALL_ 192.168.59.105:11211

# delete items with names SMALL_0 to SMALL_49999 from 192.168.59.105:11211
php memcached-keys-stats.php -d 50000:SMALL_ 192.168.59.105:11211
```

After running you'll get html report and a folder of top entites in current working directory. Tables in report have sortable columns.