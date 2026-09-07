# Railway-এ SSH ও ttyd আলাদা port-এ চালানো

এই setup-এ:

```text
Railway TCP Proxy 20494 → container 22 → sshd
Railway HTTP Domain    → container $PORT → ttyd
```

`sshd` কখনো HTTP port-এ চলবে না এবং `ttyd` কখনো SSH port `22`-এ চলবে না।

## ১. Railway সেটিংস

Service settings-এ Dockerfile path দিন:

```text
/deploy/railway.Dockerfile
```

Build context repository root (`/`) রাখুন। নতুন deploy/redeploy করুন।

Railway HTTP public domain-টি service-এর `$PORT`-এ যাবে। Startup script নিজে Railway-এর `PORT` ব্যবহার করে ttyd চালায়।

তারপর Networking-এ TCP Proxy তৈরি/রাখুন:

```text
Public TCP: altaria.proxy.rlwy.net:20494
Target: container port 22
```

HTTP public domain-এর target অবশ্যই `$PORT` হবে; সেটিকে `22` করবেন না।

## ২. Railway Variables

কমপক্ষে এই variable যোগ করুন:

```text
ROOT_PASSWORD=<একটি নতুন, দীর্ঘ, random password>
```

আরও নিরাপদ key-based SSH চাইলে:

```text
SSH_AUTHORIZED_KEY=ssh-ed25519 AAAA... আপনার-public-key
```

`SSH_AUTHORIZED_KEY` ব্যবহার করলে local machine থেকে:

```bash
ssh -p 20494 root@altaria.proxy.rlwy.net
```

password login-এর বদলে key login ব্যবহার হবে। `ROOT_PASSWORD` না দিলে startup script password login বন্ধ করে দেবে।

Web terminal-এ Basic Auth চাইলে optional variable:

```text
TTYD_CREDENTIAL=terminal-user:একটি-আলাদা-terminal-password
```

SSH password এবং ttyd password একই রাখবেন না।

## ৩. Build ও startup test

Deploy হওয়ার পরে web terminal-এ:

```bash
ss -lntp
ps aux | grep -E '[s]shd|[t]tyd'
```

Expected ধারণা:

```text
sshd  → 0.0.0.0:22 এবং [::]:22
ttyd  → 0.0.0.0:$PORT
```

`systemctl` ব্যবহার করবেন না; Railway container-এ systemd নেই।

## ৪. SSH test

Local machine থেকে:

```bash
ssh -vv -p 20494 root@altaria.proxy.rlwy.net
```

Successful হলে:

```bash
whoami
hostname
ss -lntp
```

Expected user:

```text
root
```

## ৫. নতুন password কেন দরকার

আগের password chat-এ প্রকাশিত হয়েছে। সেটি আর ব্যবহার করবেন না। Railway Variables-এ নতুন password বসিয়ে redeploy করুন। Secret source code, Dockerfile বা shell command-এ লিখবেন না।

## ৬. গুরুত্বপূর্ণ সীমাবদ্ধতা

Railway container traditional VPS নয়। Container restart/redeploy হলে runtime filesystem-এর manual changes হারিয়ে যেতে পারে। তাই package installation, sshd config এবং startup process Dockerfile/script-এ রাখা হয়েছে। Railway-তে Laravel deploy করতে সাধারণত SSH session-এর ওপর নির্ভর না করে GitHub deployment ব্যবহার করা ভালো।
