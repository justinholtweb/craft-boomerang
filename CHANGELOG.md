# Release Notes for Boomerang

## 5.0.1

### Security

- Fixed a vulnerability where the customer portal accepted lines that can't be returned, such as
  excluded SKUs, final-sale product types or lines already returned in full, when they were posted
  directly. With auto-approval on, those requests were approved automatically. The portal now checks
  every line against the eligibility verdict and caps quantities at what can still be returned.
  Customer-submitted returns are checked again when they're submitted.
- Portal photos are now restricted to images (JPEG, PNG, GIF, WebP, HEIC), with the file contents
  checked as well as the extension. Previously any file type Craft allows could be uploaded,
  including HTML. Photos are also only saved once the whole request has been accepted.
- A state's email subject and body can now only be changed by admins, because they run as Twig.
  Other users with permission to configure states can still edit the rest of the state. On Craft
  5.9+ with `enableTwigSandbox` on, these templates are also rendered in the sandbox.

## 5.0.0

- Initial release.
