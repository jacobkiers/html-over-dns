<?php declare(strict_types=1);
if (!defined('EOL')) {
    define('EOL', "\n");
}

class ContentFile
{
    private string $filePath;
    private string $data;
    private string $fileName;
    private array $metadata = [];

    #const RECORD_TTL = 60 * 60 * 24 * 30; // 30 days.
    const RECORD_TTL = 60; // Keep it short for now, so it is easier to experiment.
    const METADATA_TTL = 60; // Keep it short for now, so it is easier to update the content.
    const RECORD_LENGTH = 1536;

    public function __construct(string $file)
    {
        $this->filePath = $file;
        $this->fileName = basename(dirname($file)).'/'.basename($file);
        $this->data = file_get_contents($file);

        $this->parseMetadata();
    }

    /**
     * @return Record[]
     */
    private function toRecords(): array
    {
        $chunks = $this->calculateChunks();
        $hash = $this->calculateHash();

        $records = [$this->buildMetadataRecord(count($chunks), $hash)];
        foreach ($chunks as $id => $chunk) {
            $records[] = $this->buildRecord($id, $chunk);
        }

        return $records;
    }

    public function __toString(): string
    {
        $result = "";

        foreach ($this->toRecords() as $record) {
            $result .= $record.EOL;
        }

        return $result;
    }

    public function getDnsName(): string
    {
        return str_replace(['/', '\\', '.'], '-', $this->fileName);
    }

    private function buildMetadataRecord(int $chunkCount, string $hash): Record
    {
        return (new Record())
            ->setDnsName($this->getDnsName())
            ->setTtl(self::METADATA_TTL)
            ->setContent($this->buildIndexString($chunkCount, $hash));
    }

    private function buildIndexString(int $chunkCount, $hash): string
    {
        $metadata = base64_encode(json_encode($this->metadata));

        return '"'."t={$this->mimeType()};c={$chunkCount};ha=sha-1;h={$hash};m={$metadata}".'"';
    }

    private function buildRecord(int $id, string $data): Record
    {
        $name = "$id.{$this->calculateHash()}";

        return (new Record())
            ->setContent($data)
            ->setDnsName($name)
            ->setTtl(self::RECORD_TTL);
    }

    private function mimeType(): string
    {
        $extension = pathinfo($this->fileName, PATHINFO_EXTENSION);

        switch ($extension) {
            case "js":
                return "text/javascript";
            case "md":
                return "text/markdown";

            default:
                return mime_content_type($this->filePath);
        }
    }

    private function parseMetadata(): void
    {
        if ($this->mimeType() != "text/markdown") {
            return;
        }

        $front_matter_chars = "+++";

        $front_matter_end = strpos($this->data, $front_matter_chars, 2);
        if (false === $front_matter_end) {
            return;
        }

        $front_matter = substr(
            $this->data,
            strlen($front_matter_chars) + 1,
            $front_matter_end - strlen($front_matter_chars) - 2
        );

        $this->data = trim(substr($this->data, $front_matter_end + strlen($front_matter_chars))).EOL;

        $lines = explode(EOL, $front_matter);
        foreach ($lines as $line) {
            $eq_pos = strpos($line, "=");
            $key = trim(substr($line, 0, $eq_pos - 1));
            $value = trim(substr($line, $eq_pos + 1));
            $this->metadata[$key] = $value;
        }
    }

    private function calculateHash(): string
    {
        return sha1($this->data);
    }

    private function calculateChunks(): array
    {
        return str_split(base64_encode($this->data), self::RECORD_LENGTH);
    }
}

class Record
{
    private string $dnsName;
    private int $ttl;
    private string $content;

    public function setDnsName(string $dnsName): self
    {
        $this->dnsName = $dnsName;

        return $this;
    }

    public function setTtl(int $ttl): self
    {
        $this->ttl = $ttl;

        return $this;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getRecord(): string
    {
        return $this->dnsName
            ."\t".$this->ttl
            ."\tIN"
            ."\tTXT\t"
            .$this->content;
    }

    public function __toString(): string
    {
        return $this->getRecord();
    }
}

class StartOfAuthority
{
    public function __construct(
        private string $origin,
        private string $serial,
        private string $masterDnsServer,
        private string $domainContact,
        private int $slaveRefreshInterval,
        private int $slaveRetryInterval,
        private int $slaveCopyExpireTime,
        private int $nxDomainCacheTime
    ) {
    }

    public function doNotUserMasterDnsServer(): self
    {
        $servers = $this->dnsServers;
        $this->dnsServers = array_filter(
            $servers,
            function ($server) {
                return $server !== $this->masterDnsServer;
            }
        );

        return $this;
    }

    public function increaseSerial(): self
    {
        $matches = [];
        preg_match('#(\d{8})(\d{2})#', $this->serial, $matches);
        list (, $day, $count) = $matches;

        $today = date("Ymd");
        if ($today === $day) {
            $count++;
        } else {
            $count = 0;
        }

        $this->serial = sprintf('%1$s%2$02d', $today, $count);;

        return $this;
    }

    public function __toString(): string
    {
        return <<<EOF
\$ORIGIN $this->origin  ; The zone of this zone file
@   IN  SOA $this->masterDnsServer  $this->domainContact (
    $this->serial   ; serial
    $this->slaveRefreshInterval ; slave refresh interval
    $this->slaveRetryInterval   ; slave retry interval
    $this->slaveCopyExpireTime  ; slave copy expire time
    $this->nxDomainCacheTime    ; NXDOMAIN cache time
)

;
; domain name servers
;
@   IN  NS  $this->masterDnsServer
EOF;
    }

    public static function fromString(string $zonefile): self
    {
        $matches = [];
        $found = 1 === preg_match(
                '#\$ORIGIN\s+(?P<origin>[a-z.]+)[^@]+@\s+IN\s+SOA\s+(?P<dns>[a-z.]+)\s+(?P<contact>[a-z.]+)\s+\(\D+\s+(?P<serial>\d+)\D+(?P<srefresh>\d+)\D+(?P<sretry>\d+)\D+(?P<sexpire>\d+)\D+(?P<nxcache>\d+)[^\)]+\)#im',
                $zonefile,
                $matches
            );

        if (!$found) {
            throw new \Exception("Could not find the SOA record.");
        }

        return new self(
            $matches['origin'],
            $matches['serial'],
            $matches['dns'],
            $matches['contact'],
            (int)$matches['srefresh'],
            (int)$matches['sretry'],
            (int)$matches['sexpire'],
            (int)$matches['nxcache']
        );
    }

}

class ZoneFile
{
    private StartOfAuthority $soa;
    /** @var ContentFile[] */
    private array $files = [];
    private int $defaultTTL = 300;

    public function __construct(
        private string $file
    ) {
        $this->soa = StartOfAuthority::fromString(file_get_contents($this->file));
    }

    public function addFile(ContentFile ...$files): self
    {
        foreach ($files as $file) {
            $this->files[] = $file;
        }

        return $this;
    }

    public function setDefaultTTL(int $defaultTTL): self
    {
        $this->defaultTTL = $defaultTTL;

        return $this;
    }

    public function __toString(): string
    {
        $this->soa->increaseSerial();

        $zone_file = "\$TTL\t{$this->defaultTTL}\t; Default TTL".EOL.EOL;
        $zone_file .= $this->soa->__toString().EOL.EOL.EOL;
        $zone_file .= ";;; START BLOG RECORDS";

        foreach ($this->files as $file) {
            $zone_file .= EOL.EOL.EOL;
            $zone_file .= '; '.$file->getDnsName().EOL;
            $zone_file .= $file;
        }

        $zone_file .= EOL;

        return $zone_file;
    }
}

function update_serial_line(string $line): string
{
    $matches = [];
    preg_match("#(\s+)(\d{10})(.*)#", $line, $matches);
    list($_, $start, $serial, $last) = $matches;

    $today = date("Ymd");
    $day = substr($serial, 0, 8);
    $nr = (int)substr($serial, -2);

    if ($day === $today) {
        $nr++;
    } else {
        $day = $today;
        $nr = 1;
    }

    $new_serial = sprintf('%1$s%2$02d', $day, $nr);

    return $start.$new_serial.$last;
}

$zone_file_path = "zones/db.hod.experiments.jacobkiers.net";
if (!file_exists($zone_file_path)) {
    fwrite(STDERR, "The zone file {$zone_file_path} does not exist!");
    exit(1);
}
/*
$lines = file($zone_file);

$index = 0;
foreach ($lines as $index => &$line) {
    if (str_contains($line, "; serial")) {
        $matches = [];
        preg_match("#(\s+)(\d{10})(.*)#", $line, $matches);
        list($_, $start, $serial, $last) = $matches;
        $line = update_serial_line($line).EOL;
    }
    if (str_starts_with($line, ";; START BLOG RECORDS")) {
        break;
    }
}



$zone_file_contents = implode("", array_slice($lines, 0, $index + 1));

echo $zone_file_contents;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__."/content"));
foreach ($it as $file) {
    if ($file->isDir()) {
        continue;
    }
    if (str_contains($file->getPathname(), "ignore")) {
        continue;
    }

    $content = new ContentFile($file->getPathname());
    $zone_file_contents .= EOL.$content->__toString().EOL;
}

$zone_file_contents .= EOL;

echo $zone_file_contents;

#file_put_contents($zone_file, $zone_file_contents);
*/

$zone_file = new ZoneFile($zone_file_path);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__."/content"));
foreach ($it as $file) {
    if ($file->isDir()) {
        continue;
    }
    if (str_contains($file->getPathname(), "ignore")) {
        continue;
    }

    $content = new ContentFile($file->getPathname());
    $zone_file->addFile($content);
}

$zone_file_contents = $zone_file->__toString();
echo $zone_file_contents;
file_put_contents($zone_file_path, $zone_file_contents);