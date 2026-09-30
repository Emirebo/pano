# Pano — cihazlar arası kopyala/yapıştır

Kendi domainin altında çalışan, şifreyle korunan kişisel bir pano. Bilgisayarda bir kod parçasını veya metni gönderirsin, birkaç saniye içinde telefonda (ya da başka bir bilgisayarda) görünür. Tek tıkla kopyalarsın. Ters yönde de aynı şekilde çalışır.

## Paket içeriği

| Dosya | Ne işe yarar |
|---|---|
| `index.php` | Uygulamanın tamamı: giriş ekranı, arayüz ve API. Tek dosya. |
| `kurulum.sh` | Ubuntu/Debian VPS için tek komutluk kurulum (nginx + php-fpm + HTTPS). |
| `demo.html` | Sunucusuz demo. Bilgisayar ve telefonu yan yana gösterir; tarayıcıda çift tıklayıp açabilirsin. Veriler yalnızca sayfada tutulur. |
| `README.md` | Bu dosya. |

---

## Hızlı kurulum (VPS)

**Gerekenler:** Ubuntu 22.04+/Debian 12+ bir VPS, root/sudo yetkisi, bir domain veya alt domain.

1. **DNS:** Domain panelinde bir A kaydı oluştur. Örnek: `pano.seninsite.com` → VPS'in IP adresi. Kaydın yayılmasını bekle (genelde birkaç dakika). Kontrol etmek için: `ping pano.seninsite.com`

2. **Dosyaları sunucuya gönder** (kendi bilgisayarından):
   ```bash
   unzip pano.zip
   scp -r pano kullanici@SUNUCU_IP:~/
   ```

3. **Kur** (sunucuda):
   ```bash
   cd ~/pano
   sudo bash kurulum.sh pano.seninsite.com eposta@ornek.com
   ```
   Script senden en az 8 karakterlik bir pano şifresi ister. Ardından şunları yapar:
   - nginx, php-fpm, php-cli, php-mbstring ve certbot'u kurar
   - dosyaları `/var/www/pano` altına kopyalar
   - şifreyi `/var/www/pano/config.php` dosyasına yazar
   - nginx sitesini ayarlar
   - Let's Encrypt ile HTTPS sertifikası alır ve HTTP'yi HTTPS'e yönlendirir

4. **Güvenlik duvarı** (ufw açıksa):
   ```bash
   sudo ufw allow OpenSSH
   sudo ufw allow 'Nginx Full'
   ```

5. Her cihazda `https://pano.seninsite.com` adresini aç ve şifreyi bir kez gir. Giriş 90 gün hatırlanır.

### Sertifika alınamazsa

Genellikle DNS kaydı henüz yayılmamıştır ya da 80/443 portları kapalıdır. Bunları düzelttikten sonra şunu çalıştır:
```bash
sudo certbot --nginx -d pano.seninsite.com -m eposta@ornek.com --agree-tos --redirect
```

---

## Kullanım

- Metni kutuya yapıştır, **Gönder**'e bas (bilgisayarda **Ctrl/Cmd + Enter**).
- Kayıtlardaki **Kopyala** butonuyla kopyala. Bilgisayarda metne çift tıklamak da kopyalar.
- **Panodan yapıştır** butonu, cihazın panosundaki metni kutuya aktarır (yalnızca HTTPS'de çalışır).
- Kutuda Tab tuşu girinti ekler; kodun girintisi korunur.
- "Bu cihaz" alanına bir ad yaz ("İş PC", "Telefon" gibi). Kayıtlarda hangisinin nereden geldiği görünür. Ad, o tarayıcıda saklanır.
- En fazla 200 kayıt tutulur; yeni kayıt gelince en eski silinir. Tek kayıt en fazla 1 MB olabilir.

---

## Bakım

| İş | Komut / yol |
|---|---|
| Kayıtları yedekle | `sudo cp /var/www/pano/data/clips.php ~/pano-yedek.php` |
| Şifreyi değiştir | `sudo rm /var/www/pano/config.php && cd ~/pano && sudo bash kurulum.sh pano.seninsite.com eposta@ornek.com` |
| Uygulamayı güncelle | Yeni `index.php`'yi `~/pano/` içine koy ve kurulum scriptini tekrar çalıştır. Şifre ve kayıtlar korunur. |
| Tüm kayıtları sil | Arayüzdeki "Tümünü sil" veya `sudo rm /var/www/pano/data/clips.php` |
| nginx hata kaydı | `sudo tail -f /var/log/nginx/error.log` |

Şifre değiştirildiğinde tüm cihazlardaki oturumlar kapanır; her cihazda yeniden giriş yapman gerekir.

---

## Elle kurulum (script kullanmadan, ör. Apache veya paylaşımlı hosting)

1. `index.php`'yi web klasörüne yükle.
2. Aynı klasöre `config.php` oluştur:
   ```php
   <?php
   return 'buraya-guclu-sifre';
   ```
3. PHP'nin aynı klasörde `data/` alt klasörünü oluşturabilmesi için yazma izni ver (veya `data/` klasörünü kendin oluşturup 755/775 izni ver).
4. Apache'de `data/` içine otomatik bir `.htaccess` yazılır. Nginx kullanıyorsan `data/` ve `config.php` için erişimi kapatan kuralları ekle (`kurulum.sh` içindeki nginx bloğuna bak).
5. HTTPS'i aç. Kopyala ve "Panodan yapıştır" özellikleri tarayıcılarda yalnızca HTTPS'de tam çalışır.

Gereksinim: PHP 7.4 veya üstü. Veritabanı gerekmez.

---

## Proje bağlamı ve alınan kararlar

Bu bölüm, projeyi daha sonra devralacak kişi veya yapay zekâ asistanı için yazıldı.

**Amaç:** Kullanıcı farklı bilgisayarlarda ve telefonunda çalışıyor; sık sık kod parçası ve metin taşıması gerekiyor. Kendi domaini altında, tarayıcıdan açıp kopyala/yapıştır yapabileceği basit bir site istedi.

**Neden veritabanı yok:** Veri tek bir kayıt listesi ve bir sürüm sayacından ibaret. Kişisel kullanımda eşzamanlı yazma çok az olduğu için `flock` ile kilitlenen tek bir dosya yeterli. VPS'te disk kalıcı olduğu için bu yöntem güvenle çalışır.

**Neden Vercel değil:** Önce Vercel düşünüldü. Ancak Vercel serverless çalışır; disk kalıcı değildir ve PHP'yi resmi olarak desteklemez. Orada bir veri deposu (ör. Upstash Redis) ve Node.js fonksiyonları gerekirdi. Kullanıcı bu yüzden VPS'i seçti. İleride Vercel'e geçilmek istenirse önerilen yapı: statik `index.html` + `api/` altında Node.js fonksiyonları + Upstash Redis (liste için bir anahtar, sürüm için bir sayaç), şifre ortam değişkeninde.

**Mimari:**
- `index.php` üç şey yapar: giriş formu, arayüz HTML'i ve `?api=` altında JSON API.
- API işlemleri: `list` (GET, `rev` parametresiyle), `add`, `delete`, `clear` (POST, JSON gövde).
- Canlı güncelleme için her cihaz 3 saniyede bir `?api=list&rev=N` ile sorar. Sürüm değişmediyse sunucu yalnızca `{changed:false}` döner; trafik çok azdır. Sekme görünür değilken sorgu yapılmaz; sekmeye dönüldüğünde hemen yenilenir.
- Veri dosyası: `data/clips.php`. Başında `<?php exit; ?>` satırı vardır; doğrudan açılırsa içerik görünmez. Geri kalanı JSON'dur: `{"rev": 12, "clips": [{"id", "text", "device", "ts"}]}`. Yeni kayıtlar listenin başına eklenir.

**Güvenlik:**
- Kimlik doğrulama: Doğru şifreden sonra `pano_auth` çerezi yazılır. Değeri `hash_hmac('sha256', 'pano-auth-v1', PASSWORD)`'dur. HttpOnly, SameSite=Strict, HTTPS'de Secure. Oturum dosyası tutulmaz; şifre değişince tüm çerezler geçersiz olur.
- CSRF: SameSite=Strict çereze ek olarak POST isteklerinde `X-Pano: 1` başlığı zorunludur.
- Yanlış şifrede 1 saniye bekletme (kaba kuvvet yavaşlatma).
- Varsayılan veya 8 karakterden kısa şifreyle site çalışmaz, 503 döner.
- Kayıtlar arayüzde `textContent` ile basılır (XSS yok).
- Nginx'te `data/` ve `config.php` kapalıdır; `index.php` dışında PHP çalıştırılamaz. Sayfa `noindex` etiketlidir.

**Tasarım:**
- Fontlar: IBM Plex Sans (arayüz) ve JetBrains Mono (kayıtlar ve giriş kutusu). Google Fonts'tan yüklenir; yedek sistem fontları tanımlıdır.
- Açık tema: arka plan `#E9EDF1`, yüzey `#FFFFFF`, metin `#1A2330`, vurgu (kobalt) `#2350C8`. Koyu tema, cihazın ayarına göre otomatik uygulanır.
- Yeni gelen kayıt kısa bir süre vurgu rengiyle çerçevelenir. Sistemde "hareketi azalt" açıksa bu animasyon kapanır.

**İleride eklenebilecekler (konuşulan fikirler):**
- Dosya ve görsel gönderme
- Kayıt sabitleme
- Tasarım değişiklikleri (renk, yerleşim, buton isimleri)
