<?php declare(strict_types=1);

namespace Workbunny\WebmanRqueue;
class Header
{
    public int $_delay = 0;
    public float $_timestamp = 0.0;
    public int $_count = 0;
    public ?string $_error = null;
    public ?bool $_delete = true;

    public function __construct(array $headers = [])
    {
        $this->init($headers);
    }

    /**
     * @param array $headers
     * @return $this
     */
    public function init(array $headers): static
    {

        foreach ($headers as $key => $value) {
            if(\property_exists($this, $key)) {
                try {
                    $this->$key = $value;
                }catch (\Throwable $throwable){}
            }
        }
        return $this;
    }

    /**
     * @return $this
     */
    public function clean(): static
    {
        $this->_count     = 0;
        $this->_delay     = 0;
        $this->_delete    = true;
        $this->_error     = null;
        $this->_timestamp = 0.0;
        return $this;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return json_decode(json_encode($this),true);
    }
}