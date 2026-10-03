<?php
namespace App\SSH;

/**
 * Checks a signature pasted in during sign-in against the keys a website
 * lists. A signature carries the key that made it, so a website only has to
 * list that key's fingerprint.
 */
class Verifier {

  /**
   * @param string $keysText  the file the rel="ssh-key" link points to
   * @param string $armored   the signature as pasted
   * @param string $namespace what the signature has to have been made for
   * @param string $challenge the text it has to cover
   * @return array ['fingerprint' => string, 'type' => string]
   * @throws SSHException if any of it does not hold
   */
  public static function verify(string $keysText, string $armored, string $namespace, string $challenge): array {
    $list = KeyList::parse($keysText);
    $sig = Signature::parse($armored);

    // A signature made for anything else, a git commit or another site, is
    // not a sign-in here, however valid
    if($sig->namespace !== $namespace)
      throw new SSHException('The signature was made for "'.$sig->namespace.'" rather than "'.$namespace.'". Sign again with -n '.$namespace.'.');

    if(!$list->contains($sig->key))
      throw new SSHException('The signature was made with the key '.$sig->key->fingerprint().', which your website does not list.');

    // Either the challenge exactly, or with the newline that echo adds when
    // it is piped in without -n
    if(!$sig->covers($challenge) && !$sig->covers($challenge."\n"))
      throw new SSHException('The signature is not a valid signature over the challenge we asked you to sign.');

    return [
      'fingerprint' => $sig->key->fingerprint(),
      'type' => $sig->key->type,
    ];
  }

}
