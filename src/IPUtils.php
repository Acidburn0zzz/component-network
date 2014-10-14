<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\IP;

/**
 * IP address utilities (for both IPv4 and IPv6).
 *
 * As a matter of naming convention, we use `$ip` for the binary format (network address format)
 * and `$ipString` for the string/presentation format (i.e., human-readable form).
 */
class IPUtils
{
    /**
     * Removes the port and the last portion of a CIDR IP address.
     *
     * @param string $ipString The IP address to sanitize.
     * @return string
     */
    public static function sanitizeIp($ipString)
    {
        $ipString = trim($ipString);

        // CIDR notation, A.B.C.D/E
        $posSlash = strrpos($ipString, '/');
        if ($posSlash !== false) {
            $ipString = substr($ipString, 0, $posSlash);
        }

        $posColon = strrpos($ipString, ':');
        $posDot = strrpos($ipString, '.');
        if ($posColon !== false) {
            // IPv6 address with port, [A:B:C:D:E:F:G:H]:EEEE
            $posRBrac = strrpos($ipString, ']');
            if ($posRBrac !== false && $ipString[0] == '[') {
                $ipString = substr($ipString, 1, $posRBrac - 1);
            }

            if ($posDot !== false) {
                // IPv4 address with port, A.B.C.D:EEEE
                if ($posColon > $posDot) {
                    $ipString = substr($ipString, 0, $posColon);
                }
                // else: Dotted quad IPv6 address, A:B:C:D:E:F:G.H.I.J
            } else if (strpos($ipString, ':') === $posColon) {
                $ipString = substr($ipString, 0, $posColon);
            }
            // else: IPv6 address, A:B:C:D:E:F:G:H
        }
        // else: IPv4 address, A.B.C.D

        return $ipString;
    }

    /**
     * Sanitize human-readable (user-supplied) IP address range.
     *
     * Accepts the following formats for $ipRange:
     * - single IPv4 address, e.g., 127.0.0.1
     * - single IPv6 address, e.g., ::1/128
     * - IPv4 block using CIDR notation, e.g., 192.168.0.0/22 represents the IPv4 addresses from 192.168.0.0 to 192.168.3.255
     * - IPv6 block using CIDR notation, e.g., 2001:DB8::/48 represents the IPv6 addresses from 2001:DB8:0:0:0:0:0:0 to 2001:DB8:0:FFFF:FFFF:FFFF:FFFF:FFFF
     * - wildcards, e.g., 192.168.0.*
     *
     * @param string $ipRangeString IP address range
     * @return string|bool  IP address range in CIDR notation OR false
     */
    public static function sanitizeIpRange($ipRangeString)
    {
        $ipRangeString = trim($ipRangeString);
        if (empty($ipRangeString)) {
            return false;
        }

        // IPv4 address with wildcards '*'
        if (strpos($ipRangeString, '*') !== false) {
            if (preg_match('~(^|\.)\*\.\d+(\.|$)~D', $ipRangeString)) {
                return false;
            }

            $bits = 32 - 8 * substr_count($ipRangeString, '*');
            $ipRangeString = str_replace('*', '0', $ipRangeString);
        }

        // CIDR
        if (($pos = strpos($ipRangeString, '/')) !== false) {
            $bits = substr($ipRangeString, $pos + 1);
            $ipRangeString = substr($ipRangeString, 0, $pos);
        }

        // single IP
        if (($ip = @inet_pton($ipRangeString)) === false)
            return false;

        $maxbits = strlen($ip) * 8;
        if (!isset($bits))
            $bits = $maxbits;

        if ($bits < 0 || $bits > $maxbits) {
            return false;
        }

        return "$ipRangeString/$bits";
    }

    /**
     * Converts an IP address in presentation format to network address format.
     *
     * @param string $ipString IP address, either IPv4 or IPv6, e.g., `"127.0.0.1"`.
     * @return string Binary-safe string, e.g., `"\x7F\x00\x00\x01"`.
     */
    public static function P2N($ipString)
    {
        // use @inet_pton() because it throws an exception and E_WARNING on invalid input
        $ip = @inet_pton($ipString);
        return $ip === false ? "\x00\x00\x00\x00" : $ip;
    }

    /**
     * Convert network address format to presentation format.
     *
     * See also {@link prettyPrint()}.
     *
     * @param string $ip IP address in network address format.
     * @return string IP address in presentation format.
     */
    public static function N2P($ip)
    {
        // use @inet_ntop() because it throws an exception and E_WARNING on invalid input
        $ipStr = @inet_ntop($ip);
        return $ipStr === false ? '0.0.0.0' : $ipStr;
    }

    /**
     * Get low and high IP addresses for a specified IP range.
     *
     * @param array $ipRange An IP address range in presentation format.
     * @return array|null  Array `array($lowIp, $highIp)` in network address format, or null on failure.
     */
    public static function getIPRangeBounds($ipRange)
    {
        if (strpos($ipRange, '/') === false) {
            $ipRange = self::sanitizeIpRange($ipRange);
        }
        $pos = strpos($ipRange, '/');

        $bits = substr($ipRange, $pos + 1);
        $range = substr($ipRange, 0, $pos);
        $high = $low = @inet_pton($range);
        if ($low === false) {
            return null;
        }

        $lowLen = strlen($low);
        $i = $lowLen - 1;
        $bits = $lowLen * 8 - $bits;

        for ($n = (int)($bits / 8); $n > 0; $n--, $i--) {
            $low[$i] = chr(0);
            $high[$i] = chr(255);
        }

        $n = $bits % 8;
        if ($n) {
            $low[$i] = chr(ord($low[$i]) & ~((1 << $n) - 1));
            $high[$i] = chr(ord($high[$i]) | ((1 << $n) - 1));
        }

        return array($low, $high);
    }

    /**
     * Returns the last IP address in a comma separated list, subject to an optional exclusion list.
     *
     * @param string $csv Comma separated list of elements.
     * @param array $excludedIps Optional list of excluded IP addresses (or IP address ranges).
     * @return string Last (non-excluded) IP address in the list.
     */
    public static function getLastIpFromList($csv, $excludedIps = null)
    {
        $p = strrpos($csv, ',');
        if ($p !== false) {
            $elements = explode(',', $csv);
            for ($i = count($elements); $i--;) {
                $stringIp = trim($elements[$i]);
                $ip = IP::fromStringIP(self::sanitizeIp($stringIp));
                if (empty($excludedIps) || (!in_array($stringIp, $excludedIps) && !$ip->isInRanges($excludedIps))) {
                    return $stringIp;
                }
            }
        }
        return trim($csv);
    }
}
