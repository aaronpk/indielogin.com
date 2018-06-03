Encoding.default_internal = 'UTF-8'
require 'rubygems'
require 'bundler/setup'
require 'securerandom'

Bundler.require

GPGME::Engine.home_dir = File.dirname(__FILE__)+'/data'

class App < Sinatra::Base

  get '/' do
    'PGP Signature Verification Service'
  end

  post '/verify' do
    begin
      crypto = GPGME::Crypto.new

      # Import their public key
      result = GPGME::Key.import(params[:key])

      # Get the Key object for the key that was just imported
      key = GPGME::Key.find(:public, result.imports[0].fpr).first

      # Find the fingerprint of the key that was just imported (or had been previously imported)
      fingerprint = key.fingerprint
      puts "Fingerprint of imported key: #{key.fingerprint}"
      puts "Subkeys:"
      puts key.subkeys

      valid = false
      fingerprint_used = false
      signature_text = false

      signature = GPGME::Data.new(params[:signed])
      data = crypto.verify(signature) do |sig|
        puts sig.to_s
        signature_text = sig.to_s

        verified = sig.valid?

        if !verified
          respond({error: 'invalid_signature'}, 400)
        end

        puts sig.inspect
        puts "Fingerprint of key that was used to sign: #{sig.fpr}"
        fingerprint_used = sig.fpr

        # Check the fingerprint of any subkeys
        valid = false
        if fingerprint == sig.fpr
          puts "Matched the master key fingerprint"
          valid = true
        end
        key.subkeys.each do |subkey|
          if subkey.fingerprint == sig.fpr
            puts "Matched subkey #{subkey.fingerprint}"
            valid = true
          end
        end
      end

      if valid
        respond({
          result: 'verified',
          fingerprint: fingerprint_used,
          signature: signature_text,
          plaintext: data.read
        }, 200)
      else
        respond({
          error: 'key_mismatch',
          fingerprint: fingerprint_used,
        }, 400)
      end


    rescue => e
      puts "EXCEPTION:"
      puts e.inspect
      respond({error: 'exception', error_description: 'There was an error verifying the signed text.'}, 400)
    end
  end

  def respond(data, code=200)
    halt code, {
      'Content-Type' => 'application/json;charset=UTF-8',
      'Cache-Control' => 'no-store',
    },
    data.to_json
  end

end
