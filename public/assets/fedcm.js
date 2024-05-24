async function signIn() {
  
    const loginChallengeResponse = await fetch("/fedcm/start", {
      method: "POST",
      body: new URLSearchParams({
        client_id: $("input[name=client_id]").val(),
        redirect_uri: $("input[name=redirect_uri]").val(),
        state: $("input[name=state]").val()
      })
    });
    const loginChallenge = await loginChallengeResponse.json();
  
    const identityCredential = await navigator.credentials.get({
      identity: {
        context: "signin",
        providers: [
          {
            configURL: "any",
            clientId: loginChallenge.client_id,
            nonce: loginChallenge.code_challenge,
          },
        ],
        // mode: "button"
      },
    }).catch(e => {
      console.log("Error", e);
      
      document.getElementById("error-message").classList.remove("hidden");
      document.getElementById("error-message").innerText = "FedCM error: "+e.message;
    });
    console.log(identityCredential);
    if(identityCredential && identityCredential.token) {
      
      const {code, metadata_endpoint} = JSON.parse(identityCredential.token);

      const response = await fetch("/fedcm/login", {
        method: "POST",
        headers: {
          "Content-type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          code: code,
          metadata_endpoint: metadata_endpoint
        })
      });
      
      try {
        const responseData = await response.json();

        console.log(responseData);
        
        if(responseData && responseData.redirect) {
          window.location = responseData.redirect;
        } else {
          document.getElementById("error-message").classList.remove("hidden");
          document.getElementById("error-message").innerText = responseData.error;
        }

      } catch(err) {
        console.log(err);
        document.getElementById("error-message").classList.remove("hidden");
        document.getElementById("error-message").innerText = "Invalid response from server";
        return;
      }

    }
}

function getChromeVersion () {     
    var raw = navigator.userAgent.match(/Chrom(e|ium)\/([0-9]+)\./);
    return raw ? parseInt(raw[2], 10) : false;
}

if(navigator.credentials && getChromeVersion() >= 126) {
  signIn();
}

