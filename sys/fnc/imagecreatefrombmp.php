<?php

if (!function_exists('imagecreatefrombmp')) {
    
    function imagecreatefrombmp(string $filename): GdImage|false
    {
        // Вспомогательные функции для обработки битов
        $get4BitPixel = function(string $img, int $position): int {
            $byte = ord(substr($img, (int)floor($position), 1));
            return ($position * 2) % 2 === 0 ? $byte >> 4 : $byte & 0x0F;
        };
        
        $get1BitPixel = function(string $img, int $position): int {
            $byte = ord(substr($img, (int)floor($position), 1));
            $bitPosition = ($position * 8) % 8;
            return ($byte >> (7 - $bitPosition)) & 0x01;
        };
        
        // Открытие файла в бинарном режиме
        if (!($f1 = fopen($filename, "rb"))) {
            trigger_error('imagecreatefrombmp: Cannot open file ' . $filename, E_USER_WARNING);
            return false;
        }
        
        try {
            // 1: Загрузка заголовков ФАЙЛА
            $FILE = unpack("vfile_type/Vfile_size/Vreserved/Vbitmap_offset", fread($f1, 14));
            if ($FILE['file_type'] != 19778) { // 19778 = 'BM'
                throw new RuntimeException('Not a BMP file');
            }
            
            // 2: Загрузка заголовков BMP
            $BMP = unpack('Vheader_size/Vwidth/Vheight/vplanes/vbits_per_pixel' . 
                         '/Vcompression/Vsize_bitmap/Vhoriz_resolution' . 
                         '/Vvert_resolution/Vcolors_used/Vcolors_important', 
                         fread($f1, 40));
            
            // Валидация параметров
            if ($BMP['width'] <= 0 || $BMP['height'] <= 0) {
                throw new RuntimeException('Invalid image dimensions');
            }
            
            $BMP['colors'] = 2 ** $BMP['bits_per_pixel'];
            $BMP['size_bitmap'] = $BMP['size_bitmap'] ?? ($FILE['file_size'] - $FILE['bitmap_offset']);
            $BMP['bytes_per_pixel'] = $BMP['bits_per_pixel'] / 8;
            
            // Расчет выравнивания
            $rowSize = $BMP['width'] * $BMP['bytes_per_pixel'];
            $BMP['decal'] = (4 - ($rowSize % 4)) % 4;
            
            // 3: Загрузка цветов палитры
            $PALETTE = [];
            if ($BMP['bits_per_pixel'] <= 8) {
                $paletteSize = $BMP['colors'] * 4;
                $paletteData = fread($f1, $paletteSize);
                if (strlen($paletteData) === $paletteSize) {
                    $PALETTE = unpack('V' . $BMP['colors'], $paletteData);
                }
            }
            
            // 4: Чтение данных изображения
            fseek($f1, $FILE['bitmap_offset']);
            $IMG = fread($f1, $BMP['size_bitmap']);
            
            // Создание изображения
            $res = imagecreatetruecolor($BMP['width'], $BMP['height']);
            if (!$res) {
                throw new RuntimeException('Cannot create image');
            }
            
            // Обработка пикселей
            $P = 0;
            $VIDE = "\0";
            
            for ($Y = $BMP['height'] - 1; $Y >= 0; $Y--) {
                for ($X = 0; $X < $BMP['width']; $X++) {
                    $color = match ((int)$BMP['bits_per_pixel']) {
                        24 => unpack("V", substr($IMG, $P, 3) . $VIDE)[1],
                        16 => $PALETTE[unpack("v", substr($IMG, $P, 2))[1] + 1] ?? 0,
                        8 => $PALETTE[ord(substr($IMG, $P, 1)) + 1] ?? 0,
                        4 => $PALETTE[$get4BitPixel($IMG, $P) + 1] ?? 0,
                        1 => $PALETTE[$get1BitPixel($IMG, $P) + 1] ?? 0,
                        default => throw new RuntimeException('Unsupported BMP format: ' . $BMP['bits_per_pixel'] . ' bpp')
                    };
                    
                    imagesetpixel($res, $X, $Y, $color);
                    $P += $BMP['bytes_per_pixel'];
                }
                $P += $BMP['decal'];
            }
            
            return $res;
            
        } catch (Throwable $e) {
            trigger_error('imagecreatefrombmp: ' . $e->getMessage(), E_USER_WARNING);
            return false;
        } finally {
            fclose($f1);
        }
    }
}

?>
