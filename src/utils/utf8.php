<?php

/**
 * Convert a string into valid UTF-8. This function is quite slow.
 *
 * When invalid byte subsequences are encountered, they will be replaced with
 * U+FFFD, the Unicode replacement character.
 *
 * @param   string  String to convert to valid UTF-8.
 * @return  string  String with invalid UTF-8 byte subsequences replaced with
 *                  U+FFFD.
 */
function phutil_utf8ize($string) {
  if (phutil_is_utf8($string)) {
    return $string;
  }

  // There is no function to do this in iconv, mbstring or ICU to do this, so
  // do it (very very slowly) in pure PHP.

  // TODO: Provide an optional fast C implementation ala fb_utf8ize() if this
  // ever shows up in profiles?

  // NOTE: Overlong 3-byte and 4-byte representations incorrectly survive
  // this function.

  $result = array();

  $regex =
    "/([\x01-\x7F]".
    "|[\xC2-\xDF][\x80-\xBF]".
    "|[\xE0-\xEF][\x80-\xBF][\x80-\xBF]".
    "|[\xF0-\xF4][\x80-\xBF][\x80-\xBF][\x80-\xBF])".
    "|(.)/";

  $offset = 0;
  $matches = null;
  while (preg_match($regex, $string, $matches, 0, $offset)) {
    if (!isset($matches[2])) {
      $result[] = $matches[1];
    } else {
      // Unicode replacement character, U+FFFD.
      $result[] = "\xEF\xBF\xBD";
    }
    $offset += strlen($matches[0]);
  }

  return implode('', $result);
}


/**
 * Determine if a string is valid UTF-8, with only basic multilingual plane
 * characters. This is particularly important because MySQL's `utf8` column
 * types silently truncate strings which contain characters outside of this
 * set.
 *
 * @param string  String to test for being valid UTF-8 with only characters in
 *                the basic multilingual plane.
 * @return bool   True if the string is valid UTF-8 with only BMP characters.
 */
function phutil_is_utf8_with_only_bmp_characters($string) {
  return phutil_is_utf8_slowly($string, $only_bmp = true);
}


/**
 * Determine if a string is valid UTF-8.
 *
 * @param string  Some string which may or may not be valid UTF-8.
 * @return bool    True if the string is valid UTF-8.
 */
function phutil_is_utf8($string) {
  if (function_exists('mb_check_encoding')) {
    // If mbstring is available, this is significantly faster than using PHP.
    return mb_check_encoding($string, 'UTF-8');
  }

  return phutil_is_utf8_slowly($string);
}


/**
 * Determine if a string is valid UTF-8, slowly.
 *
 * This works on any system, but has very poor performance.
 *
 * You should call @{function:phutil_is_utf8} instead of this function, as
 * that function can use more performant mechanisms if they are available on
 * the system.
 *
 * @param string  Some string which may or may not be valid UTF-8.
 * @param bool    True to require all characters be part of the basic
 *                multilingual plane (no more than 3-bytes long).
 * @return bool   True if the string is valid UTF-8.
 */
function phutil_is_utf8_slowly($string, $only_bmp = false) {
  // First, check the common case of normal ASCII strings. We're fine if
  // the string contains no bytes larger than 127.
  if (preg_match('/^[\x01-\x7F]+\z/', $string)) {
    return true;
  }

  // NOTE: In the past, we used a large regular expression in the form of
  // '(x|y|z)+' to match UTF8 strings. However, PCRE can segfaults on patterns
  // like this at relatively small input sizes, at least on some systems
  // (observed on OSX and Windows). This is apparently because the internal
  // implementation is recursive and it blows the stack.

  // See <https://bugs.php.net/bug.php?id=45735> for some discussion. Since the
  // input limit is extremely low (less than 50KB on my system), do this check
  // very very slowly in PHP instead. See also T5316.

  $len = strlen($string);
  for ($ii = 0; $ii < $len; $ii++) {
    $chr = ord($string[$ii]);
    if ($chr >= 0x01 && $chr <= 0x7F) {
      continue;
    } else if ($chr >= 0xC2 && $chr <= 0xDF) {
      ++$ii;
      if ($ii >= $len) {
        return false;
      }
      $chr = ord($string[$ii]);
      if ($chr >= 0x80 && $chr <= 0xBF) {
        continue;
      }
      return false;
    } else if ($chr > 0xE0 && $chr <= 0xEF) {
      ++$ii;
      if ($ii >= $len) {
        return false;
      }
      $chr = ord($string[$ii]);
      if ($chr >= 0x80 && $chr <= 0xBF) {
        ++$ii;
        if ($ii >= $len) {
          return false;
        }
        $chr = ord($string[$ii]);
        if ($chr >= 0x80 && $chr <= 0xBF) {
          continue;
        }
      }
      return false;
    } else if ($chr == 0xE0) {
      ++$ii;
      if ($ii >= $len) {
        return false;
      }
      $chr = ord($string[$ii]);

      // NOTE: This range starts at 0xA0, not 0x80. The values 0x80-0xA0 are
      // "valid", but not minimal representations, and MySQL rejects them. We're
      // special casing this part of the range.

      if ($chr >= 0xA0 && $chr <= 0xBF) {
        ++$ii;
        if ($ii >= $len) {
          return false;
        }
        $chr = ord($string[$ii]);
        if ($chr >= 0x80 && $chr <= 0xBF) {
          continue;
        }
      }
      return false;
    } else if (!$only_bmp) {
      if ($chr > 0xF0 && $chr <= 0xF4) {
        ++$ii;
        if ($ii >= $len) {
          return false;
        }
        $chr = ord($string[$ii]);
        if ($chr >= 0x80 && $chr <= 0xBF) {
          ++$ii;
          if ($ii >= $len) {
            return false;
          }
          $chr = ord($string[$ii]);
          if ($chr >= 0x80 && $chr <= 0xBF) {
            ++$ii;
            if ($ii >= $len) {
              return false;
            }
            $chr = ord($string[$ii]);
            if ($chr >= 0x80 && $chr <= 0xBF) {
              continue;
            }
          }
        }
      } else if ($chr == 0xF0) {
        ++$ii;
        if ($ii >= $len) {
          return false;
        }
        $chr = ord($string[$ii]);

        // NOTE: As above, this range starts at 0x90, not 0x80. The values
        // 0x80-0x90 are not minimal representations.

        if ($chr >= 0x90 && $chr <= 0xBF) {
          ++$ii;
          if ($ii >= $len) {
            return false;
          }
          $chr = ord($string[$ii]);
          if ($chr >= 0x80 && $chr <= 0xBF) {
            ++$ii;
            if ($ii >= $len) {
              return false;
            }
            $chr = ord($string[$ii]);
            if ($chr >= 0x80 && $chr <= 0xBF) {
              continue;
            }
          }
        }
      }
    }

    return false;
  }

  return true;
}


/**
 * Find the character length of a UTF-8 string.
 *
 * @param string A valid utf-8 string.
 * @return int   The character length of the string.
 */
function phutil_utf8_strlen($string) {
  return strlen(utf8_decode($string));
}


/**
 * Find the console display length of a UTF-8 string. This may differ from the
 * character length of the string if it contains double-width characters, like
 * many Chinese characters.
 *
 * This method is based on a C implementation here, which is based on the IEEE
 * standards. The source has more discussion and addresses more considerations
 * than this implementation does.
 *
 *   http://www.cl.cam.ac.uk/~mgk25/ucs/wcwidth.c
 *
 * NOTE: We currently assume width 1 for East-Asian ambiguous characters.
 *
 * NOTE: This function is VERY slow.
 *
 * @param   string  A valid UTF-8 string.
 * @return  int     The console display length of the string.
 */
function phutil_utf8_console_strlen($string) {
  // Formatting and colors don't contribute any width in the console.
  $string = preg_replace("/\x1B\[\d*m/", '', $string);

  // In the common case of an ASCII string, just return the string length.
  if (preg_match('/^[\x01-\x7F]*\z/', $string)) {
    return strlen($string);
  }

  $len = 0;

  // NOTE: To deal with combining characters, we're splitting the string into
  // glyphs first (characters with combiners) and then counting just the width
  // of the first character in each glyph.

  $display_glyphs = phutil_utf8v_combined($string);
  foreach ($display_glyphs as $display_glyph) {
    $glyph_codepoints = phutil_utf8v_codepoints($display_glyph);
    foreach ($glyph_codepoints as $c) {
      if ($c == 0) {
        break;
      }

      $len += 1 +
        ($c >= 0x1100 &&
          ($c <= 0x115f ||                    /* Hangul Jamo init. consonants */
            $c == 0x2329 || $c == 0x232a ||
            ($c >= 0x2e80 && $c <= 0xa4cf &&
              $c != 0x303f) ||                  /* CJK ... Yi */
            ($c >= 0xac00 && $c <= 0xd7a3) || /* Hangul Syllables */
            ($c >= 0xf900 && $c <= 0xfaff) || /* CJK Compatibility Ideographs */
            ($c >= 0xfe10 && $c <= 0xfe19) || /* Vertical forms */
            ($c >= 0xfe30 && $c <= 0xfe6f) || /* CJK Compatibility Forms */
            ($c >= 0xff00 && $c <= 0xff60) || /* Fullwidth Forms */
            ($c >= 0xffe0 && $c <= 0xffe6) ||
            ($c >= 0x20000 && $c <= 0x2fffd) ||
            ($c >= 0x30000 && $c <= 0x3fffd)));

      break;
    }
  }

  return $len;
}


/**
 * Split a UTF-8 string into an array of characters. Combining characters are
 * also split.
 *
 * @param string A valid utf-8 string.
 * @return list  A list of characters in the string.
 */
function phutil_utf8v($string) {
  $res = array();
  $len = strlen($string);
  $ii = 0;
  while ($ii < $len) {
    $byte = $string[$ii];
    if ($byte <= "\x7F") {
      $res[] = $byte;
      $ii += 1;
      continue;
    } else if ($byte < "\xC0") {
      throw new Exception(
        pht('Invalid UTF-8 string passed to %s.', __FUNCTION__));
    } else if ($byte <= "\xDF") {
      $seq_len = 2;
    } else if ($byte <= "\xEF") {
      $seq_len = 3;
    } else if ($byte <= "\xF7") {
      $seq_len = 4;
    } else if ($byte <= "\xFB") {
      $seq_len = 5;
    } else if ($byte <= "\xFD") {
      $seq_len = 6;
    } else {
      throw new Exception(
        pht('Invalid UTF-8 string passed to %s.', __FUNCTION__));
    }

    if ($ii + $seq_len > $len) {
      throw new Exception(
        pht('Invalid UTF-8 string passed to %s.', __FUNCTION__));
    }
    for ($jj = 1; $jj < $seq_len; ++$jj) {
      if ($string[$ii + $jj] >= "\xC0") {
        throw new Exception(
          pht('Invalid UTF-8 string passed to %s.', __FUNCTION__));
      }
    }
    $res[] = substr($string, $ii, $seq_len);
    $ii += $seq_len;
  }
  return $res;
}


/**
 * Split a UTF-8 string into an array of codepoints (as integers).
 *
 * @param   string  A valid UTF-8 string.
 * @return  list    A list of codepoints, as integers.
 */
function phutil_utf8v_codepoints($string) {
  $str_v = phutil_utf8v($string);

  foreach ($str_v as $key => $char) {
    $c = ord($char[0]);
    $v = 0;

    if (($c & 0x80) == 0) {
      $v = $c;
    } else if (($c & 0xE0) == 0xC0) {
      $v = (($c & 0x1F) << 6)
         + ((ord($char[1]) & 0x3F));
    } else if (($c & 0xF0) == 0xE0) {
      $v = (($c & 0x0F) << 12)
         + ((ord($char[1]) & 0x3f) << 6)
         + ((ord($char[2]) & 0x3f));
    } else if (($c & 0xF8) == 0xF0) {
      $v = (($c & 0x07) << 18)
         + ((ord($char[1]) & 0x3F) << 12)
         + ((ord($char[2]) & 0x3F) << 6)
         + ((ord($char[3]) & 0x3f));
    } else if (($c & 0xFC) == 0xF8) {
      $v = (($c & 0x03) << 24)
         + ((ord($char[1]) & 0x3F) << 18)
         + ((ord($char[2]) & 0x3F) << 12)
         + ((ord($char[3]) & 0x3f) << 6)
         + ((ord($char[4]) & 0x3f));
    } else if (($c & 0xFE) == 0xFC) {
      $v = (($c & 0x01) << 30)
         + ((ord($char[1]) & 0x3F) << 24)
         + ((ord($char[2]) & 0x3F) << 18)
         + ((ord($char[3]) & 0x3f) << 12)
         + ((ord($char[4]) & 0x3f) << 6)
         + ((ord($char[5]) & 0x3f));
    }

    $str_v[$key] = $v;
  }

  return $str_v;
}


/**
 * Hard-wrap a block of UTF-8 text with embedded HTML tags and entities.
 *
 * @param   string An HTML string with tags and entities.
 * @return  list   List of hard-wrapped lines.
 */
function phutil_utf8_hard_wrap_html($string, $width) {
  $break_here = array();

  // Convert the UTF-8 string into a list of UTF-8 characters.
  $vector = phutil_utf8v($string);
  $len = count($vector);
  $char_pos = 0;
  for ($ii = 0; $ii < $len; ++$ii) {
    // An ampersand indicates an HTML entity; consume the whole thing (until
    // ";") but treat it all as one character.
    if ($vector[$ii] == '&') {
      do {
        ++$ii;
      } while ($vector[$ii] != ';');
      ++$char_pos;
    // An "<" indicates an HTML tag, consume the whole thing but don't treat
    // it as a character.
    } else if ($vector[$ii] == '<') {
      do {
        ++$ii;
      } while ($vector[$ii] != '>');
    } else {
      ++$char_pos;
    }

    // Keep track of where we need to break the string later.
    if ($char_pos == $width) {
      $break_here[$ii] = true;
      $char_pos = 0;
    }
  }

  $result = array();
  $string = '';
  foreach ($vector as $ii => $char) {
    $string .= $char;
    if (isset($break_here[$ii])) {
      $result[] = $string;
      $string = '';
    }
  }

  if (strlen($string)) {
    $result[] = $string;
  }

  return $result;
}

/**
  * Hard-wrap a block of UTF-8 text with no embedded HTML tags and entities.
  *
  * @param string A non HTML string
  * @param int Width of the hard-wrapped lines
  * @return list List of hard-wrapped lines.
  */
function phutil_utf8_hard_wrap($string, $width) {
  $result = array();

  $lines = phutil_split_lines($string, $retain_endings = false);
  foreach ($lines as $line) {

    // Convert the UTF-8 string into a list of UTF-8 characters.
    $vector = phutil_utf8v($line);

    $len = count($vector);
    $buffer = '';

    for ($ii = 1; $ii <= $len; ++$ii) {
      $buffer .= $vector[$ii - 1];
      if (($ii % $width) === 0) {
        $result[] = $buffer;
        $buffer = '';
      }
    }

    if (strlen($buffer)) {
      $result[] = $buffer;
    }
  }

  return $result;
}

/**
 * Convert a string from one encoding (like ISO-8859-1) to another encoding
 * (like UTF-8).
 *
 * This is primarily a thin wrapper around `mb_convert_encoding()` which checks
 * you have the extension installed, since we try to require the extension
 * only if you actually need it (i.e., you want to work with encodings other
 * than UTF-8).
 *
 * NOTE: This function assumes that the input is in the given source encoding.
 * If it is not, it may not output in the specified target encoding. If you
 * need to perform a hard conversion to UTF-8, use this function in conjunction
 * with @{function:phutil_utf8ize}. We can detect failures caused by invalid
 * encoding names, but `mb_convert_encoding()` fails silently if the
 * encoding name identifies a real encoding but the string is not actually
 * encoded with that encoding.
 *
 * @param string String to re-encode.
 * @param string Target encoding name, like "UTF-8".
 * @param string Source encoding name, like "ISO-8859-1".
 * @return string Input string, with converted character encoding.
 *
 * @phutil-external-symbol function mb_convert_encoding
 */
function phutil_utf8_convert($string, $to_encoding, $from_encoding) {
  if (!$from_encoding) {
    throw new InvalidArgumentException(
      pht(
        'Attempting to convert a string encoding, but no source encoding '.
        'was provided. Explicitly provide the source encoding.'));
  }
  if (!$to_encoding) {
    throw new InvalidArgumentException(
      pht(
        'Attempting to convert a string encoding, but no target encoding '.
        'was provided. Explicitly provide the target encoding.'));
  }

  // Normalize encoding names so we can no-op the very common case of UTF8
  // to UTF8 (or any other conversion where both encodings are identical).
  $to_upper = strtoupper(str_replace('-', '', $to_encoding));
  $from_upper = strtoupper(str_replace('-', '', $from_encoding));
  if ($from_upper == $to_upper) {
    return $string;
  }

  if (!function_exists('mb_convert_encoding')) {
    throw new Exception(
      pht(
        "Attempting to convert a string encoding from '%s' to '%s', ".
        "but the '%s' PHP extension is not available. Install %s to ".
        "work with encodings other than UTF-8.",
        $from_encoding,
        $to_encoding,
        'mbstring',
        'mbstring'));
  }

  $result = @mb_convert_encoding($string, $to_encoding, $from_encoding);

  if ($result === false) {
    $message = error_get_last();
    if ($message) {
      $message = idx($message, 'message', pht('Unknown error.'));
    }
    throw new Exception(
      pht(
        "String conversion from encoding '%s' to encoding '%s' failed: %s",
        $from_encoding,
        $to_encoding,
        $message));
  }

  return $result;
}


/**
 * Convert a string to title case in a UTF8-aware way. This function doesn't
 * necessarily do a great job, but the builtin implementation of `ucwords()` can
 * completely destroy inputs, so it just has to be better than that. Similar to
 * @{function:ucwords}.
 *
 * @param   string  UTF-8 input string.
 * @return  string  Input, in some semblance of title case.
 */
function phutil_utf8_ucwords($str) {
  // NOTE: mb_convert_case() discards uppercase letters in words when converting
  // to title case. For example, it will convert "AAA" into "Aaa", which is
  // undesirable.

  $v = phutil_utf8v($str);
  $result = '';
  $last = null;

  $ord_a = ord('a');
  $ord_z = ord('z');
  foreach ($v as $c) {
    $convert = false;
    if ($last === null || $last === ' ') {
      $o = ord($c[0]);
      if ($o >= $ord_a && $o <= $ord_z) {
        $convert = true;
      }
    }

    if ($convert) {
      $result .= phutil_utf8_strtoupper($c);
    } else {
      $result .= $c;
    }

    $last = $c;
  }

  return $result;
}


/**
 * Convert a string to lower case in a UTF8-aware way. Similar to
 * @{function:strtolower}.
 *
 * @param   string  UTF-8 input string.
 * @return  string  Input, in some semblance of lower case.
 *
 * @phutil-external-symbol function mb_convert_case
 */
function phutil_utf8_strtolower($str) {
  if (function_exists('mb_convert_case')) {
    return mb_convert_case($str, MB_CASE_LOWER, 'UTF-8');
  }

  static $map;
  if ($map === null) {
    $map = array_combine(
      range('A', 'Z'),
      range('a', 'z'));
  }

  return phutil_utf8_strtr($str, $map);
}


/**
 * Convert a string to upper case in a UTF8-aware way. Similar to
 * @{function:strtoupper}.
 *
 * @param   string  UTF-8 input string.
 * @return  string  Input, in some semblance of upper case.
 *
 * @phutil-external-symbol function mb_convert_case
 */
function phutil_utf8_strtoupper($str) {
  if (function_exists('mb_convert_case')) {
    return mb_convert_case($str, MB_CASE_UPPER, 'UTF-8');
  }

  static $map;
  if ($map === null) {
    $map = array_combine(
      range('a', 'z'),
      range('A', 'Z'));
  }

  return phutil_utf8_strtr($str, $map);
}


/**
 * Replace characters in a string in a UTF-aware way. Similar to
 * @{function:strtr}.
 *
 * @param   string              UTF-8 input string.
 * @param   map<string, string> Map of characters to replace.
 * @return  string              Input with translated characters.
 */
function phutil_utf8_strtr($str, array $map) {
  $v = phutil_utf8v($str);
  $result = '';
  foreach ($v as $c) {
    if (isset($map[$c])) {
      $result .= $map[$c];
    } else {
      $result .= $c;
    }
  }

  return $result;
}

/**
 * Determine if a given unicode character is a combining character or not.
 *
 * @param   string              A single unicode character.
 * @return  boolean             True or false.
 */
function phutil_utf8_is_combining_character($character) {
  $components = phutil_utf8v_codepoints($character);

  // Combining Diacritical Marks (0300 - 036F).
  // Combining Diacritical Marks Supplement (1DC0 - 1DFF).
  // Combining Diacritical Marks for Symbols (20D0 - 20FF).
  // Combining Half Marks (FE20 - FE2F).

  foreach ($components as $codepoint) {
    if ($codepoint >= 0x0300 && $codepoint <= 0x036F ||
         $codepoint >= 0x1DC0 && $codepoint <= 0x1DFF ||
         $codepoint >= 0x20D0 && $codepoint <= 0x20FF ||
         $codepoint >= 0xFE20 && $codepoint <= 0xFE2F) {
      return true;
    }
  }

  return false;
}

/**
 * Split a UTF-8 string into an array of characters. Combining characters
 * are not split.
 *
 * @param string A valid utf-8 string.
 * @return list  A list of characters in the string.
 */
function phutil_utf8v_combined($string) {
  $components = phutil_utf8v($string);
  $array_length = count($components);

  // If the first character in the string is a combining character,
  // prepend a space to the string.
  if (
    $array_length > 0 &&
    phutil_utf8_is_combining_character($components[0])) {
    $string = ' '.$string;
    $components = phutil_utf8v($string);
    $array_length++;
  }

  for ($index = 1; $index < $array_length; $index++) {
    if (phutil_utf8_is_combining_character($components[$index])) {
      $components[$index - 1] =
        $components[$index - 1].$components[$index];

      unset($components[$index]);
      $components = array_values($components);

      $index--;
      $array_length = count($components);
    }
  }

  return $components;
}

/**
 * Format a system LANG to encoding string.
 *
 * @param string A LANG string.
 * @return string A encoding string with lowercase.
 */
function phutil_utf8_convertlang($lang) {
    $dotPos = strpos($lang, '.');
    if (false !== $dotPos) {
        $lang = substr($lang, $dotPos+1);
    }
    return strtolower($lang);
}

/**
 * A Recursive implemens for phutil_utf8_convert
 *
 * @param array.
 * @return array with encoding.
 */

function phutil_utf8_recursive_convert($arr, $toEncoding, $fromEncoding='', $convertKey=false) {
    $toEncoding = strtolower($toEncoding);
    $fromEncoding = strtolower($fromEncoding);
    if (empty($arr) || $toEncoding == $fromEncoding) {
        return $arr;

    }
    if (is_array($arr)) {
        $res = array();
        foreach ($arr as $key => $value) {
            if ($convertKey && $toEncoding != strtolower(mb_detect_encoding($key))) {
                $key = phutil_utf8_convert($key, $toEncoding, $fromEncoding);
            }
            if (is_array($value)) {
                $value = phutil_utf8_convert_iterator($value, $toEncoding, $fromEncoding, $convertKey);
            } else {
                if ($toEncoding != strtolower(mb_detect_encoding($value))) {
                    $value = phutil_utf8_convert($value, $toEncoding, $fromEncoding);

                }
            }
            $res[$key] = $value;

        }
    } else {
        if ($toEncoding != strtolower(mb_detect_encoding($arr))) {
            $res = phutil_utf8_convert($arr, $toEncoding, $fromEncoding);

        }
    }
    return $res;
}
