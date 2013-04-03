from sys import path
from os.path import dirname, abspath

# Add the ampy module to our PYTHONPATH before importing it
ampy_dir = dirname(abspath(__file__)) + '/workers/python'
path.append(ampy_dir)
import ampy

# ------------------------------- DEFINE SOME FUNCTIONS HERE ---------------------------------------

def my_func(workload):
    return workload

def hello_world(arg = None):
    return 'Hello, World!'

# ------------------------------- LISTEN FOR WORK DISPATCHES ---------------------------------------

if __name__ == "__main__":
    
    callables = {
        'len':          len,            # Native len() function
        'my_func':      my_func,        # Custom function defined above
        'hello_world':  hello_world     # Custom function defined above
    }
    
    ampy.listen(callables)
