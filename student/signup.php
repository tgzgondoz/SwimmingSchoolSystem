<?php
include __DIR__ . '/../inc/db.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $name = $_POST['name']; $email = $_POST['email']; $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,"student")');
    $stmt->bind_param('sss',$name,$email,$password);
    if($stmt->execute()){
        header('Location: login.php'); exit();
    } else {
        $error = 'Unable to create account (email may exist).';
    }
}
?>
<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Sign Up</title>
<link href='../css/style.css' rel='stylesheet'>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
</head><body class='d-flex justify-content-center align-items-center vh-100' style='background:#f5f7fb;'>
<div class='card p-4' style='width:360px;'>
<h3 class='mb-3'>Student Sign Up</h3>
<?php if(isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
<form method='post'>
<input class='form-control mb-2' name='name' type='text' placeholder='Full name' required>
<input class='form-control mb-2' name='email' type='email' placeholder='Email' required>
<input class='form-control mb-2' name='password' type='password' placeholder='Password' required>
<button class='btn btn-primary w-100'>Create Account</button>
</form>
<p class='mt-2 text-center'>Already have an account? <a href='login.php'>Login</a></p>
</div>
</body></html>