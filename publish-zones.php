<?php declare(strict_types=1);

class Content
{
    public string $data;
    public string $mimeType;
    public string $fileName;
    public string $hash;
    private array $metadata = [];

    const TTL = 60;

    public function __construct(string $file)
    {
        $this->fileName = basename(dirname($file)).'/'.basename($file);
        $this->data = file_get_contents($file);
        $this->hash = md5($this->data);

        $this->parseMetadata();
    }

    public function toRecords(): string
    {
        $chunks = str_split(base64_encode($this->data), 250);

        $records = [];
        $records[] = "; {$this->getDnsName()}";
        $records[] = $this->getDnsName().$this->buildMiddle().'"'.$this->buildIndexString(count($chunks)).'"';

        foreach($chunks as $id => $chunk)
        {
            $records[] = $this->buildRecord($id, $chunk);
        }

        $result = "";
        foreach ($records as $record)
        {
            $result .= $record . PHP_EOL;
        }

        return $result;
    }

    private function getDnsName(): string
    {
        return str_replace(['/', '\\', '.'], '-', $this->fileName);
    }

    private function buildIndexString(int $chunkCount): string
    {
        $metadata = base64_encode(json_encode($this->metadata));
        return "t={$this->mimeType()};c={$chunkCount};h={$this->hash};m={$metadata}";
    }

    private function buildMiddle(): string
    {
        return "\t".self::TTL."\tIN\tTXT\t";
    }

    private function buildRecord(int $id, string $data): string
    {
        $name = "$id.{$this->hash}";

        return "$name".$this->buildMiddle().'"'.$data.'"';
    }

    private function mimeType()
    {
        $extension = pathinfo($this->fileName, PATHINFO_EXTENSION);

        switch($extension)
        {
            case "js":
                return "text/javascript";
            case "md":
                return "text/markdown";
            
            default:
                return mime_content_type($this->file);
        }
    }

    private function parseMetadata()
    {
        if ($this->mimeType() != "text/markdown") return [];

        $frontmatter_chars = "+++";

        $end_of_frontmatter = strpos($this->data, $frontmatter_chars, 2);
        if (false === $end_of_frontmatter) {
            var_dump($end_of_frontmatter);
            return [];
        }

        $frontmatter = substr(
            $this->data,
            strlen($frontmatter_chars) + 1,
            $end_of_frontmatter - strlen($frontmatter_chars) - 2
        );

        $this->data = trim(substr($this->data, $end_of_frontmatter + strlen($frontmatter_chars))).PHP_EOL;

        $lines = explode(PHP_EOL, $frontmatter);
        foreach($lines as $line) {
            $eq_pos = strpos($line, "=");
            $key = trim(substr($line, 0, $eq_pos - 1));
            $value = trim(substr($line, $eq_pos + 1));
            $this->metadata[$key] = $value;
        }
    }
}

function update_serial_line(string $line)
{
    $matches = [];
    preg_match("#(\s+)(\d{10})(.*)#", $line, $matches);
    list($_, $start, $serial, $last) = $matches;

    $today = date("Ymd");
    $day = substr($serial, 0, 8);
    $nr = (int) substr($serial, -2);

    if ($day === $today) {
        $nr++;
    } else {
        $day = $today;
        $nr = 1;
    }

    $new_serial = sprintf('%1$s%2$02d', $day, $nr);

    return $start.$new_serial.$last;
}

$zone_file = "zones/db.hod.experiments.jacobkiers.net";
if (!file_exists($zone_file)) {
    fwrite(STDERR, "The zone file {$zone_file} does not exist!");
    exit(1);
}

$lines = file($zone_file);

foreach($lines as $index => &$line)
{
    if (str_contains($line, "; serial")) {
        $matches = [];
        preg_match("#(\s+)(\d{10})(.*)#", $line, $matches);
        list($_, $start, $serial, $last) = $matches;
        $line = update_serial_line($line).PHP_EOL;
    }
    if (str_starts_with($line, ";; START BLOG RECORDS")) break;
}

$zone_file_contents = implode("", array_slice($lines, 0, $index+1));

echo $zone_file_contents;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__."/content"));
foreach ($it as $file)
{
    if ($file->isDir()) continue;
    if (str_contains($file->getPathname(), "ignore")) continue;

    $bootstrap = new Content($file->getPathname());
    $zone_file_contents .= PHP_EOL.$bootstrap->toRecords().PHP_EOL;
}

$zone_file_contents .= PHP_EOL;

#echo $zone_file_contents;

file_put_contents($zone_file, $zone_file_contents);