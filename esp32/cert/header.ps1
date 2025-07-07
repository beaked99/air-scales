@"
#pragma once
#include <pgmspace.h>
const char CERT_PEM[] PROGMEM = R"EOF(
$(Get-Content -Raw server.crt)
)EOF";
"@ | Set-Content cert.h -Encoding ascii

@"
#pragma once
#include <pgmspace.h>
const char PRIVATE_KEY_PEM[] PROGMEM = R"KEY(
$(Get-Content -Raw server.key)
)KEY";
"@ | Set-Content private_key.h -Encoding ascii
